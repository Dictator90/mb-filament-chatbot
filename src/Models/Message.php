<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MB\FilamentChatbot\Database\Factories\MessageFactory;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Concerns\ResolvesOperatorModel;

/**
 * One line in a conversation.
 *
 * The body is stored exactly as it arrived, without normalising markup. Every
 * channel formats differently, and converting Telegram's bold into some neutral
 * form would mean maintaining the translation in both directions forever, purely
 * for decoration — while quietly corrupting any message that happened to contain
 * the markers as text.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use ResolvesOperatorModel;

    protected $table = 'chatbot_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'author' => MessageAuthor::class,
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return MessageFactory::new();
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * The files sent with this line.
     *
     * A relation rather than a key in `meta`, because a file has to be
     * addressable on its own by the route that serves it — see the note on the
     * attachments table. Empty for almost every message, which is why both
     * transcripts eager-load it instead of asking per row.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }

    /**
     * The line this one quotes. Self-referential and nullable, since most
     * messages quote nothing and a channel that supports replies may still
     * deliver the quoted message after the reply itself.
     *
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /**
     * The operator who typed this, when one did. Same configured class and same
     * missing foreign key as the conversation's assignee — see
     * `src/Models/Concerns/ResolvesOperatorModel.php`.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(static::operatorModel(), 'user_id');
    }

    /**
     * @param  Builder<Message>  $query
     */
    public function scopeIncoming(Builder $query): void
    {
        $query->where('direction', MessageDirection::Incoming);
    }

    /**
     * @param  Builder<Message>  $query
     */
    public function scopeOutgoing(Builder $query): void
    {
        $query->where('direction', MessageDirection::Outgoing);
    }

    public function isIncoming(): bool
    {
        return $this->direction === MessageDirection::Incoming;
    }

    /**
     * Has the channel actually accepted it. Until it has, the panel shows a
     * clock instead of a tick: the operator has to be able to see that the
     * customer does not have this message yet, or they will wait for an answer
     * to something that never left the building.
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
