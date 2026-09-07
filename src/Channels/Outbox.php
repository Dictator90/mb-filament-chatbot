<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Jobs\DeliverMessage;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use Throwable;

/**
 * Gets an already-written message out of the building.
 *
 * The message exists before this class is involved — the engine wrote it, or an
 * operator typed it — and this only decides how it reaches the person and what
 * the row says afterwards. Keeping the two apart is what lets a scenario keep
 * walking while Telegram is down: speaking succeeded, delivering has not yet.
 */
class Outbox
{
    public function __construct(
        private readonly ChatbotRegistry $registry,
    ) {}

    /**
     * Queues delivery of one outgoing message.
     *
     * **Never sends inline.** A provider answers in about a second when it is
     * healthy, and does not answer at all when it is not — and the request that
     * would be waiting is a visitor's own, held open behind somebody else's
     * outage while their browser spins. Queued, the conversation is already
     * saved and on screen; the network is somebody else's problem, retried by a
     * worker.
     */
    public function dispatch(Message $message): void
    {
        if (! $this->isDeliverable($message)) {
            return;
        }

        DeliverMessage::dispatch((int) $message->getKey());
    }

    /**
     * Hands the message to its channel and records what happened.
     *
     * Called from the job, never from a request. The result is returned as well
     * as written, because the job has to know whether waiting could help.
     */
    public function deliver(Message $message): DeliveryResult
    {
        if (! $this->isDeliverable($message)) {
            return DeliveryResult::sent($message->external_id);
        }

        $conversation = $message->conversation;

        if ($conversation === null) {
            return $this->record($message, DeliveryResult::failed('The conversation is gone.', retryable: false));
        }

        $driver = $this->registry->channel((string) $conversation->channel);

        /*
         * A message with nowhere to go is marked failed with the channel named
         * in the reason, not dropped and not left pending forever. Both of those
         * look identical to an operator watching the thread — the customer never
         * answers — and only one of them is visible on the row. The channel is
         * missing because it was switched off, uninstalled or misspelled in a
         * provider, and all three are fixed by someone reading that sentence.
         */
        if ($driver === null) {
            return $this->record($message, DeliveryResult::failed(
                "No driver is registered for channel [{$conversation->channel}].",
                retryable: false,
            ));
        }

        if (! $driver->isConfigured()) {
            return $this->record($message, DeliveryResult::failed(
                "Channel [{$driver->key()}] is not configured.",
                retryable: false,
            ));
        }

        try {
            $result = $driver->send($conversation, $message);
        } catch (Throwable $exception) {
            $result = DeliveryResult::failed($exception->getMessage());
        }

        return $this->record($message, $result);
    }

    /**
     * Whether this row is still waiting to go out.
     *
     * Incoming messages are not delivered anywhere, and an already delivered one
     * must never be sent twice: a retried job, a replayed queue or an operator
     * pressing send again would otherwise put the same sentence on the
     * customer's screen a second time.
     */
    public function isDeliverable(Message $message): bool
    {
        return $message->direction === MessageDirection::Outgoing
            && $message->delivered_at === null;
    }

    /**
     * Records that the visitor's own browser has now been given these messages.
     *
     * The site widget is the one channel where delivery is a fact about a
     * request rather than about a job, so this is where the stamp is put: the
     * transcript endpoint has just handed these rows to the person they were
     * written for, and nothing later in the day will know that better.
     *
     * **Only the rows that are not stamped yet, and only when there are any.**
     * The endpoint behind this is a poll — every few seconds, per open widget,
     * all day. Stamping whatever the poll returned would be a write per message
     * per poll, and a `where delivered_at is null` left to the database would
     * still be a statement per poll on a thread where nothing has changed. The
     * filter is done over the collection already in memory, so a poll that
     * brings nothing new writes nothing at all.
     *
     * @param  iterable<Message>  $messages
     */
    public function confirmFetched(iterable $messages): void
    {
        $ids = [];

        foreach ($messages as $message) {
            if ($this->isDeliverable($message)) {
                $ids[] = $message->getKey();
            }
        }

        if ($ids === []) {
            return;
        }

        Message::query()->whereKey($ids)->update(['delivered_at' => now()]);
    }

    /**
     * Stamps the outcome on the message.
     *
     * A success clears the previous failure, because the row states the current
     * situation rather than a history: a message that went out on the second
     * attempt is delivered, and leaving the first attempt's reason on it would
     * have operators chasing a problem that fixed itself.
     *
     * `delivered_at` is written only when the outcome is itself proof of
     * arrival. An accepted message — the site widget's — has to wait for the
     * browser that will fetch it, and a timestamp written here would be this
     * application asserting something only that browser can know.
     */
    private function record(Message $message, DeliveryResult $result): DeliveryResult
    {
        if ($result->ok()) {
            $message->forceFill(array_merge([
                'failed_at' => null,
                'failure_reason' => null,
                'external_id' => $message->external_id ?? $result->externalId(),
            ], $result->delivered() ? ['delivered_at' => now()] : []))->save();

            return $result;
        }

        $message->forceFill([
            'failed_at' => now(),
            'failure_reason' => mb_substr((string) $result->error(), 0, 255),
        ])->save();

        return $result;
    }
}
