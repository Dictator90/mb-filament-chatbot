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
 * it last asked — which also means this driver is never what knows whether a
 * message arrived. The fetch is.
 *
 * It is still a driver, and still goes through the outbox, because the
 * alternative is a special case in every place that sends. "Everything is
 * queued and stamped, except site, which is written straight through" is the
 * kind of exception that survives exactly until someone adds a second one. What
 * it does instead is answer `sendsOutward()` with false: the outbox asks the
 * driver, and every place that sends goes on calling the same method.
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
     * Accepts the message without claiming it has landed.
     *
     * **Accepted rather than sent, and the difference is the whole point.** This
     * runs in a queued job, and a job says nothing about the visitor: run a
     * worker and every message is stamped delivered the instant it is written,
     * run none and nothing is ever stamped at all — the operator's screen said
     * "sending…" under a message the visitor had answered. What actually
     * delivers a message here is the widget asking for the transcript, so the
     * stamp belongs to that request and this one leaves the column alone.
     *
     * A null id rather than a made-up one. The column is what a channel's own
     * identifier goes in, and filling it with something this package invented
     * would make the redelivery guard — which matches on exactly that column —
     * compare our fiction against the provider's facts.
     */
    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        return DeliveryResult::accepted();
    }

    public function supportsButtons(): bool
    {
        return true;
    }

    /**
     * Nothing leaves. The widget comes and takes the message, so queueing a
     * delivery for it would enqueue a job whose entire effect is to call the
     * method above and stamp no column at all.
     */
    public function sendsOutward(): bool
    {
        return false;
    }
}
