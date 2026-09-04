<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use MB\FilamentChatbot\Database\Factories\ConversationFactory;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Models\Concerns\ResolvesOperatorModel;

/**
 * A thread with one person on one channel.
 *
 * It lives forever and is never started twice: the pair of channel and external
 * id is unique, so somebody writing again six months later lands back in their
 * own thread with the whole history in it. Closing removes it from the desk, it
 * does not end it — the next incoming message opens it again.
 *
 * Two morph pairs tie the thread to the host's world: `contact` is who this
 * person is, `subject` is what the conversation is about. The package writes
 * them, indexes them, and hands them to the host. It never looks inside them,
 * and no code here may start to: the day it assumed the contact was a customer
 * with a name, it would stop working for a helpdesk whose contacts are assets.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use ResolvesOperatorModel;

    protected $table = 'chatbot_conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'bot_state' => BotState::class,
            'bot_slot_attempts' => 'array',
            'bot_answers' => 'array',
            'bot_stack' => 'array',
            'meta' => 'array',
            'identified_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_incoming_at' => 'datetime',
            'agent_read_at' => 'datetime',
            'visitor_read_at' => 'datetime',
            'bot_handed_off_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ConversationFactory::new();
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->oldest('id');
    }

    /**
     * The latest message, as a relation rather than the last element of
     * `messages`. The conversation list eager-loads it; taken from the
     * collection instead, every row on screen would fetch its own transcript
     * just to render one line of preview.
     *
     * @return HasOne<Message, $this>
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }

    /**
     * Whoever the host says this person is. Resolved only when the host asks
     * for it — the package itself must never touch the result.
     */
    public function contact(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whatever the host says this conversation is about: an order, a ticket, a
     * booking. Same rule as `contact` — stored and returned, never inspected.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The member of staff answering.
     *
     * The related class comes from configuration rather than a literal, because
     * the package has no idea what the host calls its users or which connection
     * they live on. That is also why the column carries no foreign key: there is
     * no fixed table to constrain it against.
     *
     * @return BelongsTo<Model, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(static::operatorModel(), 'assigned_to_id');
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ConversationStatus::Open);
    }

    /**
     * Threads with something the operator has not seen.
     *
     * Two timestamps compared, not a counter column. A counter would have to be
     * raised on every incoming message and cleared on every open, and the first
     * race between two webhooks would leave it permanently wrong with nothing to
     * recompute it from.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNotNull('last_incoming_at')
            ->where(fn (Builder $scoped): Builder => $scoped
                ->whereNull('agent_read_at')
                ->orWhereColumn('agent_read_at', '<', 'last_incoming_at'));
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeForChannel(Builder $query, string $channel): void
    {
        $query->where('channel', $channel);
    }

    /**
     * Has this person proved who they are.
     *
     * Not the same as "we know their phone number": a phone number may have been
     * typed into the widget's form by anyone, and there is nothing here to check
     * it against. This is the host telling us it authenticated the visitor. The
     * difference matters, because linking a thread to a real record is what gets
     * decided on this answer.
     */
    public function isIdentified(): bool
    {
        return $this->identified_at !== null;
    }

    /**
     * What to call this person in the list.
     *
     * Deliberately never asks `contact` for a name. Falling through to the
     * linked record would mean loading a host model the package knows nothing
     * about — one query per row in the list, for a class that may not even
     * expose a name. Hosts that want their own naming override this by
     * resolving `contact` themselves.
     */
    public function title(): string
    {
        if (filled($this->display_name)) {
            return (string) $this->display_name;
        }

        if (filled($this->username)) {
            return '@'.$this->username;
        }

        if (filled($this->contact_phone)) {
            return (string) $this->contact_phone;
        }

        if (filled($this->contact_email)) {
            return (string) $this->contact_email;
        }

        return 'Conversation #'.$this->getKey();
    }

    /**
     * Is the bot staying out of this conversation.
     *
     * True for a thread the bot never ran and for one a human has taken over.
     * The two are separate states for a reason (see BotState), but every caller
     * that only wants to know "should I generate a reply" asks here, so the
     * question is answered once instead of as a pair of comparisons repeated
     * across the engine, the channels and the panel.
     */
    public function botIsSilent(): bool
    {
        return $this->bot_state !== BotState::Active;
    }

    /**
     * Marks everything up to now as read by the operator.
     *
     * Stamped with the current moment rather than `last_incoming_at`: a message
     * may arrive while the operator is reading, and copying the incoming
     * timestamp would silently mark that one read too.
     */
    public function markReadByAgent(): void
    {
        $this->forceFill(['agent_read_at' => now()])->save();
    }

    /**
     * Whether anything is waiting for the operator, answered from the row that
     * is already loaded. Mirrors `scopeUnread`, and has to keep mirroring it:
     * were they to disagree, the list would show a thread the badge does not
     * count, and nobody would be able to tell which of the two is lying.
     */
    public function hasUnread(): bool
    {
        if ($this->last_incoming_at === null) {
            return false;
        }

        return $this->agent_read_at === null
            || $this->agent_read_at->lt($this->last_incoming_at);
    }
}
