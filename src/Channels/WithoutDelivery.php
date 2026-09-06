<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * Runs a rehearsal with nothing queued behind it.
 *
 * A rehearsal must not be able to send anybody anything. Every reply the bot
 * writes queues a delivery job, and a rehearsal on a live installation would
 * otherwise put its scripted answers in front of whoever the channel thinks the
 * thread belongs to. The same switch keeps a `delay` node from scheduling a
 * continuation nobody will ever run.
 *
 * A discarding connection rather than a fake, because this runs in production as
 * well as in a suite, and production has no test doubles in it.
 *
 * Shared by the quality runner and the panel's sandbox rather than written twice
 * for the same reason it exists at all: the copy that is one line out of date is
 * the one that delivers a rehearsal to a customer, and nothing about the code
 * would say which copy a given screen used.
 */
final class WithoutDelivery
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(callable $work): mixed
    {
        $previous = config('queue.default');

        config([
            'queue.connections.chatbot_rehearsal' => ['driver' => 'null'],
            'queue.default' => 'chatbot_rehearsal',
        ]);

        try {
            return $work();
        } finally {
            config(['queue.default' => $previous]);
        }
    }
}
