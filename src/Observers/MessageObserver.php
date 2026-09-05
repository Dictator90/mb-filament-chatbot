<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Observers;

use MB\FilamentChatbot\Channels\Outbox;
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
    }
}
