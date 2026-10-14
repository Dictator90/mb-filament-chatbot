<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * What a provider said when it was asked.
 *
 * Three outcomes rather than a boolean, because the middle one is the common
 * one and has nowhere else to live: some providers cannot be set up from here
 * at all. Telegram and MAX accept a webhook address over their API; WhatsApp
 * and VK register it in their own dashboards, and the honest answer to "install
 * the webhook" there is an address to copy and a sentence about where to paste
 * it — not a failure.
 */
final readonly class ChannelStatus
{
    private function __construct(
        public bool $ok,
        public string $message,
        public bool $manual = false,
    ) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * The provider has no API for this, and a person has to do it.
     *
     * Not an error: nothing is broken, and the message carries what to do.
     */
    public static function manual(string $message): self
    {
        return new self(true, $message, manual: true);
    }
}
