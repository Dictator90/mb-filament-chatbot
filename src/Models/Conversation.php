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
use MB\FilamentChatbot\Channels\ChannelText;
use MB\FilamentChatbot\Database\Factories\ConversationFactory;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageDirection;
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

    /**
     * The key under `meta` that marks a thread as a rehearsal rather than a
     * person. Written by the quality runner and read by the global scope below.
     */
    public const string QUALITY_META_KEY = 'quality_check';

    /**
     * The key under `meta` that marks a thread as somebody trying the bot out
     * from the panel. Written by the sandbox and read by the same global scope.
     */
    public const string SANDBOX_META_KEY = 'sandbox';

    /**
     * The name of the global scope, so that lifting it reads as a decision.
     */
    public const string REAL_SCOPE = 'real';

    protected $table = 'chatbot_conversations';

    protected $guarded = [];

    /**
     * A rehearsal thread is hidden from every query in the package.
     *
     * A global scope rather than a `real()` scope each screen remembers to call,
     * because "each screen" is not a list anybody holds: the inbox, the unread
     * badge, the returning-visitor condition and the widget's own lookup all ask
     * this table, and every one of them was written before quality checks
     * existed. A scope that has to be added is the scope that will be missing
     * from the next caller — and the symptom is an operator opening a thread
     * with a customer who never wrote, answering it, and finding nobody there.
     *
     * The second thing it buys is silence: `Message::conversation` is scoped
     * too, so the outbox finds no thread behind a rehearsal's reply and cannot
     * deliver it to a real chat.
     *
     * The runner lifts it by name, which is the only place that may.
     *
     * A rehearsal comes from two places now — the quality runner and the panel's
     * sandbox — and both are hidden by this one scope rather than by a second
     * one beside it. Two scopes would be two chances for a screen to lift the
     * wrong one, and the thing they hide is identical: a thread nobody wrote.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::REAL_SCOPE, static function (Builder $query): void {
            $column = $query->getModel()->qualifyColumn('meta');

            foreach (self::rehearsalMetaKeys() as $key) {
                $query->whereNull($column.'->'.$key);
            }
        });
    }

    /**
     * Every `meta` key that means "this thread is a rehearsal".
     *
     * Named once so that a third kind of rehearsal has one list to join instead
     * of a scope, a deletion sweep and a screen to be told about separately.
     *
     * @return list<string>
     */
    public static function rehearsalMetaKeys(): array
    {
        return [self::QUALITY_META_KEY, self::SANDBOX_META_KEY];
    }

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
     * The scenario the bot is currently in the middle of.
     *
     * @return BelongsTo<Scenario, $this>
     */
    public function botScenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'bot_scenario_id');
    }

    /**
     * The published version the conversation entered on.
     *
     * Pinned at entry rather than read live, so that editing a scenario does not
     * change the shape of a conversation already halfway through it. A visitor
     * answering a question that no longer exists is the failure this prevents.
     *
     * @return BelongsTo<ScenarioVersion, $this>
     */
    public function botVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'bot_version_id');
    }

    /**
     * The open run, if the bot is mid-scenario.
     *
     * A plain column with no foreign key: the runs table is created after this
     * one, and SQLite cannot add a constraint to a table that already exists.
     *
     * @return BelongsTo<Run, $this>
     */
    public function botRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'bot_run_id');
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function botRuns(): HasMany
    {
        return $this->hasMany(Run::class, 'conversation_id');
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
     * Unanswered work this person is expected to pick up: still waiting, and
     * either theirs or nobody's.
     *
     * A named scope because two screens ask it — the unread badge beside the
     * desk in the menu, and the dashboard tile that says how much of the queue
     * is the viewer's. Written out twice, the two would eventually disagree
     * about what "mine" means, and there would be no way to tell which of the
     * numbers on screen is the lying one.
     *
     * A conversation a colleague has taken is not this person's work; counting
     * the whole desk would show every operator the same number and mean nothing
     * to any of them.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeWaitingFor(Builder $query, ?int $userId): void
    {
        $query->open()
            ->unread()
            ->where(fn (Builder $scoped): Builder => $scoped
                ->whereNull('assigned_to_id')
                ->orWhere('assigned_to_id', $userId));
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
     * Two letters for the circle beside a conversation in the list.
     *
     * They are how a rail of twenty threads is scanned without being read: a
     * shape and a pair of letters are recognised at a glance, twenty similar
     * names are not.
     *
     * Taken from {@see self::title()}, so a thread with nothing but a phone
     * number gets the first digits of it — which is still something to aim at,
     * and still tells two threads apart. A nameless one gets a question mark
     * rather than an empty circle: empty reads as a hole in the page, and
     * somebody will report it as one.
     *
     * The leading `@` of a username and the `+` of a phone number are dropped
     * first: both are punctuation that every row from that channel would share,
     * so a circle showing them would be the same circle on every line.
     *
     * A name of two words gives one letter from each; anything that is a single
     * run of characters gives its first two. Not one letter — a lone letter in a
     * circle is not initials, and it collides with every other name starting the
     * same way, which is the one thing the circle exists to prevent.
     */
    public function initials(): string
    {
        $words = array_values(array_filter(
            array_map(
                static fn (string $word): string => ltrim($word, '@+#'),
                preg_split('/\s+/u', trim($this->title()), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ),
            static fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return '?';
        }

        return mb_strtoupper(count($words) === 1
            ? mb_substr($words[0], 0, 2)
            : mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
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

    /**
     * The options the last thing the bot said is waiting on.
     *
     * From the latest outgoing message and not accumulated, because options
     * belong to one question: somebody now being asked for a phone number must
     * not still be offered the buttons from the question before it.
     *
     * One definition, asked by the widget and by both sandboxes. It used to live
     * only inside the widget's controller, and the sandboxes — which read the
     * transcript straight out of the messages table — showed an `ask` node as a
     * bare line of text with nothing to answer it: the author saw the question,
     * saw no options, and concluded the node was broken rather than the screen.
     *
     * @return list<array{label: string}>
     */
    public function pendingChoices(): array
    {
        /*
         * `reorder`, not `latest`. The relation already sorts oldest first, and
         * `latest('id')` only appends a second clause — the query goes out as
         * `order by id asc, id desc`, the first one wins, and `first()` hands
         * back the *oldest* outgoing message. The widget's own version of this
         * carried the same mistake and nobody could see it: nothing wrote
         * options at all, so both the right message and the wrong one answered
         * "no options" and the query looked correct for as long as it could not
         * matter.
         */
        $last = $this->messages()
            ->where('direction', MessageDirection::Outgoing)
            ->reorder('id', 'desc')
            ->first();

        if ($last === null) {
            return [];
        }

        return array_map(
            static fn (string $label): array => ['label' => $label],
            ChannelText::options($last),
        );
    }
}
