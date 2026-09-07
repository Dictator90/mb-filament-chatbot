<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Observers;

use MB\FilamentChatbot\Channels\Outbox;
use MB\FilamentChatbot\Events\MessageArrived;
use MB\FilamentChatbot\Models\Message;

/**
 * Puts every new outgoing message on its way.
 *
 * An observer rather than a call at each writing site, because there are
 * already several of those — the engine's say(), the panel's reply box, a
 * host's own code — and they will keep appearing. A caller that forgets to
 * queue delivery produces a message that is in the transcript, visible to the
 * operator, and never seen by the customer; nothing about the row says so until
 * somebody asks why there was no reply.
 *
 * Only `created` is watched. Editing an already delivered message must not send
 * it again, and delivery itself writes to the row — reacting to `updated` would
 * make the outbox trigger itself.
 */
class MessageObserver
{
    public function __construct(
        private readonly Outbox $outbox,
    ) {}

    public function created(Message $message): void
    {
        $this->outbox->dispatch($message);

        /*
         * Announced here, beside the dispatch, for the same reason the dispatch
         * is here: there are several places a message gets written and there
         * will be more. An announcement made at each of them is an announcement
         * the next caller forgets, and the symptom — a widget that updates for
         * messages from one path and not another — reads as a flaky socket
         * rather than as a missing line.
         *
         * Dispatched whether or not live updates are switched on. The event
         * decides for itself whether it reaches a socket, so a host listening
         * for its own reasons gets it either way, and there is one code path
         * rather than two.
         */
        MessageArrived::dispatch($message);
    }
}
