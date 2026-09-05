<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MB\FilamentChatbot\Channels\Outbox;
use MB\FilamentChatbot\Models\Message;
use RuntimeException;

/**
 * Sends one outgoing message to its channel.
 *
 * Queued rather than done in the request that produced the message, because the
 * request belongs to a person waiting for a screen to finish loading and the
 * delivery belongs to somebody else's HTTP API. Inline, a provider taking ten
 * seconds means the visitor's own message takes ten seconds to appear.
 *
 * Carries the id and not the model. A serialized model would be restored from
 * the row as it was when the job was queued, and delivery reads exactly the
 * field — delivered_at — that the previous attempt may have just written.
 */
class DeliverMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Attempts, read from configuration at construction rather than as a
     * property, so that an installation can lower it for a channel that
     * charges per call.
     */
    public int $tries;

    public function __construct(
        public readonly int $messageId,
    ) {
        $this->tries = max(1, (int) config('chatbot.channels.delivery.tries', 3));
    }

    /**
     * Growing waits between attempts.
     *
     * A provider that just refused is usually still refusing a second later, and
     * hammering it is how an installation earns a rate limit on top of the
     * outage it already has.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(Outbox $outbox): void
    {
        $message = Message::query()->with('conversation')->find($this->messageId);

        if ($message === null) {
            return;
        }

        /*
         * Retrying is only ever the answer to a failure that might not repeat.
         * A message with no driver, or a channel with no credentials, fails the
         * same way on every attempt: throwing here would spend the whole retry
         * budget to arrive at the reason already written on the row, and delay
         * it reaching the operator by however long the backoff lasts.
         */
        $result = $outbox->deliver($message);

        if ($result->ok() || ! $result->retryable()) {
            return;
        }

        throw new RuntimeException(
            "Delivering message [{$this->messageId}] failed: ".(string) $result->error(),
        );
    }
}
