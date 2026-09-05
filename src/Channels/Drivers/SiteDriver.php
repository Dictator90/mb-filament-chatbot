<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels\Drivers;

use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * The chat widget embedded on the host's own site.
 *
 * The only channel that delivers by doing nothing. There is no server to call:
 * the browser asks for the transcript and gets whatever has been written since
 * it last asked, so the moment the row exists the message is as delivered as it
 * is ever going to be.
 *
 * It is still a driver, and still goes through the outbox, because the
 * alternative is a special case in every place that sends. "Everything is
 * queued and stamped, except site, which is written straight through" is the
 * kind of exception that survives exactly until someone adds a second one.
 */
final class SiteDriver implements ChannelDriver
{
    public const KEY = 'site';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Site';
    }

    /**
     * Configured means switched on. There are no credentials to check: the
     * widget talks to this application and nothing else.
     */
    public function isConfigured(): bool
    {
        return (bool) config('chatbot.channels.site.enabled', true);
    }

    /**
     * Marks the message deliverable, with no external id.
     *
     * A null id rather than a made-up one. The column is what a channel's own
     * identifier goes in, and filling it with something this package invented
     * would make the redelivery guard — which matches on exactly that column —
     * compare our fiction against the provider's facts.
     */
    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        return DeliveryResult::sent();
    }

    public function supportsButtons(): bool
    {
        return true;
    }
}
