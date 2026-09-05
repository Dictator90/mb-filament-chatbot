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
    ) {}

    /**
     * The channel accepted the message.
     *
     * The external id is optional because not every channel has one. The site
     * widget hands out no identifiers at all, and inventing a placeholder would
     * make the column unusable for the channels that do return one.
     */
    public static function sent(?string $externalId = null): self
    {
        return new self(true, $externalId, null, false);
    }

    public static function failed(string $reason, bool $retryable = true): self
    {
        return new self(false, null, $reason, $retryable);
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
}
