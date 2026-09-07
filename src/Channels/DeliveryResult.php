<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * What a channel made of an outgoing message.
 *
 * A returned value rather than an exception, because failing to deliver is an
 * ordinary outcome of talking to somebody else's server, not a defect in this
 * application. Modelled as a result, the failure lands on the message row where
 * an operator can see it; modelled as an exception, it would either bubble out
 * of an unrelated request or be swallowed by a catch that had to guess which
 * throwables meant "the provider said no".
 *
 * `retryable` separates "the provider was unreachable" from "there is nothing
 * to reach". A missing driver never becomes present by waiting, so retrying it
 * only fills the queue with work that is guaranteed to fail and delays the
 * failure reason reaching the operator.
 */
final class DeliveryResult
{
    private function __construct(
        private readonly bool $ok,
        private readonly ?string $externalId,
        private readonly ?string $error,
        private readonly bool $retryable,
        private readonly bool $delivered,
    ) {}

    /**
     * The channel accepted the message and it is now with the other side.
     *
     * The external id is optional because not every channel has one. The site
     * widget hands out no identifiers at all, and inventing a placeholder would
     * make the column unusable for the channels that do return one.
     */
    public static function sent(?string $externalId = null): self
    {
        return new self(true, $externalId, null, false, true);
    }

    /**
     * The channel is happy, and somebody other than this job decides when the
     * message has arrived.
     *
     * There for the site widget, where the queue is not part of the delivery at
     * all: the visitor's browser fetches the transcript itself, so a message is
     * with them when their widget asks for it and not a moment before. Stamped
     * as delivered by the job — which is what `sent()` here used to mean — the
     * row said "delivered" while a worker was running and "sending…" for as long
     * as one was not, and neither sentence had anything to do with whether the
     * visitor had the message. The distinction lives on the result rather than
     * as an `if ($channel === 'site')` in the outbox, because the next channel
     * whose arrival is confirmed elsewhere — a mail provider's own webhook, say
     * — needs the same answer and must not need a second special case.
     */
    public static function accepted(?string $externalId = null): self
    {
        return new self(true, $externalId, null, false, false);
    }

    public static function failed(string $reason, bool $retryable = true): self
    {
        return new self(false, null, $reason, $retryable, false);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether this outcome is itself proof that the message arrived.
     *
     * False on an accepted one, and the outbox then leaves `delivered_at` alone
     * rather than writing a timestamp it would have made up.
     */
    public function delivered(): bool
    {
        return $this->delivered;
    }
}
