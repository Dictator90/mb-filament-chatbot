<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Who is asking — assembled from server state, and from nowhere else.
 *
 * This is the single most security-sensitive class in the package, and the rule
 * it exists to enforce is one sentence: a value that narrows a query to one
 * person's rows comes from here, and from here only.
 *
 * Everything else within reach at query time was written by, or influenced by,
 * whoever is typing in the chat. The message text obviously. But also the
 * conversation's `bot_answers`, which is nothing but the visitor's own replies
 * stored under friendly names; also anything a language model produced, since
 * it produced it after reading the visitor's message; also the step's settings
 * once a scenario author has been persuaded to point one at an answer. None of
 * those may reach a scope value. A visitor who can name their own customer id
 * is a visitor who can name somebody else's, and the query that results is a
 * perfectly valid, perfectly indexed, perfectly fast read of another person's
 * records — with nothing in the logs to distinguish it from a legitimate one.
 *
 * So the scope declaration never carries a value, only a `source` string naming
 * where the value is to be fetched from at query time, and this class is what
 * resolves it. The value is never shown to the model and never shown to the
 * scenario author: a scope that can be read is a scope that can be argued with.
 *
 * An unknown source resolves to `null`, and `null` MUST refuse the query rather
 * than drop the filter. This is the whole reason the method returns null instead
 * of throwing somewhere convenient: the failure it guards against is a typo.
 * `actor.tenant_id` written where the host meant `tenant.id` is a source nobody
 * will notice is wrong, because the query keeps working — it just stops being
 * restricted, and one resource quietly starts answering with the whole table.
 * A missing value has to be the loudest outcome here, not the most permissive.
 */
final class RuntimeContext
{
    /**
     * @param  array<string, mixed>  $tenant
     */
    public function __construct(
        private readonly ?Conversation $conversation = null,
        private readonly mixed $actor = null,
        private readonly array $tenant = [],
    ) {}

    /**
     * The context of a message that arrived on a conversation.
     *
     * The actor is passed in rather than read from `auth()` inside this class,
     * because the same read runs in three places where the authenticated user is
     * three different things — a panel request, a queued job continuing a
     * scenario, an inbound webhook with no session at all — and a class that
     * guesses would be right in the first case and silently wrong in the other
     * two. Whoever builds the context knows; this class does not have to.
     *
     * @param  array<string, mixed>  $tenant
     */
    public static function forConversation(Conversation $conversation, mixed $actor = null, array $tenant = []): self
    {
        return new self($conversation, $actor, $tenant);
    }

    /**
     * Resolves one source string, or null when there is nothing behind it.
     *
     * Null covers three cases on purpose — an unknown prefix, a known prefix with
     * nothing bound to it, and a known attribute that happens to be empty — and
     * the caller treats all three the same way, by refusing. Telling them apart
     * would invite a caller to handle one of them by carrying on.
     */
    public function resolve(string $source): mixed
    {
        $source = trim($source);

        if ($source === '') {
            return null;
        }

        [$prefix, $path] = array_pad(explode('.', $source, 2), 2, null);

        return match ($prefix) {
            'actor' => $this->fromActor((string) $path),
            'tenant' => $this->fromTenant((string) $path),
            'conversation' => $this->fromConversation((string) $path),
            default => null,
        };
    }

    /**
     * Reads an attribute of the authenticated actor.
     *
     * Only an Eloquent model or an array is accepted. An arbitrary object would
     * mean reaching for a public property or a getter by name, and a name that
     * resolves to a method call is a name that can be made to do something other
     * than read a value.
     */
    private function fromActor(string $path): mixed
    {
        if ($path === '' || $this->actor === null) {
            return null;
        }

        if ($this->actor instanceof Model) {
            return $path === 'id'
                ? self::flatten($this->actor->getKey())
                : self::flatten($this->actor->getAttribute($path));
        }

        if (is_array($this->actor)) {
            return self::flatten($this->actor[$path] ?? null);
        }

        return null;
    }

    private function fromTenant(string $path): mixed
    {
        return $path === '' ? null : self::flatten($this->tenant[$path] ?? null);
    }

    /**
     * The conversation facts a scope may be built on.
     *
     * A closed list rather than a lookup on the model, because the conversation
     * row also holds `bot_answers` — the visitor's own words — and a generic
     * `conversation.<column>` reader would put those one typo away from being a
     * scope value.
     */
    private function fromConversation(string $path): mixed
    {
        if ($this->conversation === null) {
            return null;
        }

        return match ($path) {
            'id' => self::flatten($this->conversation->getKey()),
            'contact_id' => self::flatten($this->conversation->contact_id),
            'contact_type' => self::flatten($this->conversation->contact_type),
            'subject_id' => self::flatten($this->conversation->subject_id),
            'subject_type' => self::flatten($this->conversation->subject_type),
            'channel' => self::flatten($this->conversation->channel),
            default => null,
        };
    }

    /**
     * Reduces a resolved value to something that may be bound to a query.
     *
     * An object reaching a where-clause is either an accident or an injection
     * attempt; either way the driver's idea of how to bind it is not a decision
     * this package wants made for it.
     */
    private static function flatten(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return null;
    }
}
