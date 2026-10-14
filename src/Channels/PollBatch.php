<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * One pull's worth of messages, and where to carry on from.
 *
 * A failure is a value here rather than an exception because the poller is a
 * loop over several accounts: one provider refusing a token must not end the
 * pass for the others, and it must be writable onto the connection so the
 * reason shows up on the settings screen instead of only in a log nobody opens.
 */
final class PollBatch
{
    /**
     * @param  list<InboundMessage>  $messages
     */
    private function __construct(
        public readonly array $messages,
        public readonly ?string $cursor,
        public readonly ?string $failure,
    ) {}

    /**
     * @param  list<InboundMessage>  $messages
     */
    public static function of(array $messages, ?string $cursor): self
    {
        return new self($messages, $cursor, null);
    }

    public static function empty(): self
    {
        return new self([], null, null);
    }

    public static function failed(string $reason): self
    {
        return new self([], null, $reason);
    }
}
