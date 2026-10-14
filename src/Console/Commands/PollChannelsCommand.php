<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Illuminate\Console\Command;
use MB\FilamentChatbot\Channels\ChannelPolling;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use Throwable;

/**
 * Fetches waiting messages for every connection set to be polled.
 *
 * The second way in, for installations where the first one cannot work: a
 * provider that is unable to reach this server delivers nothing however
 * correctly its webhook is registered, and on a Russian host that is the normal
 * state of Telegram rather than a misconfiguration. See `ChannelPolling`.
 *
 * **Long-running by default, one pass with `--once`.** The loop is what makes
 * an answer immediate — each pull waits at the provider until something
 * arrives, so a reply costs no more delay than a webhook would. `--once` exists
 * for the scheduler and for tests, where an endless loop is not an option.
 *
 * **One account's failure never ends the pass.** A revoked token, a provider
 * outage, a connection somebody mistyped — each is written onto its own row and
 * the next account is asked anyway. The alternative puts every bot on the
 * installation behind the worst-configured one.
 */
class PollChannelsCommand extends Command
{
    protected $signature = 'chatbot:poll
                            {--once : Make a single pass instead of looping}
                            {--channel= : Only this channel, e.g. telegram}
                            {--wait=20 : Seconds to wait at the provider for something to arrive}';

    protected $description = 'Fetch waiting messages for channels delivered by polling';

    public function handle(ChatbotRegistry $registry, MessageRouter $router): int
    {
        $wait = max(1, (int) $this->option('wait'));
        $running = true;

        /*
         * Under systemd a stop arrives as SIGTERM, and the signal lands while
         * the process is blocked in a long poll. Trapping it lets the pass
         * finish and the cursor be saved; without it the stop is a kill, the
         * batch is refetched on the next start, and every message in it is
         * answered twice.
         */
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        do {
            $polled = $this->pass($registry, $router, $wait);

            if ($polled === 0) {
                $this->components->info('No connections are set to be polled.');

                if (! $this->option('once')) {
                    sleep($wait);
                }
            }
        } while ($running && ! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * One round over every polled connection; returns how many were asked.
     */
    protected function pass(ChatbotRegistry $registry, MessageRouter $router, int $wait): int
    {
        $asked = 0;

        foreach ($this->connections() as $connection) {
            $driver = $registry->channel($connection->channel);

            if (! $driver instanceof ChannelPolling) {
                continue;
            }

            $asked++;

            try {
                $batch = $driver->pull($connection, $connection->poll_cursor, $wait);
            } catch (Throwable $exception) {
                $this->note($connection, $exception->getMessage());

                continue;
            }

            if ($batch->failure !== null) {
                $this->note($connection, $batch->failure);

                continue;
            }

            $refused = null;

            foreach ($batch->messages as $inbound) {
                try {
                    $router->receive($inbound);
                } catch (Throwable $exception) {
                    /*
                     * One message the bot cannot answer must not stop the
                     * others, and above all must not stop the loop. A scenario
                     * whose settings make a step throw used to end the process:
                     * systemd restarted it, the same update was fetched again,
                     * it threw again, and every bot on the installation went
                     * quiet over one node in one graph. The message itself is
                     * already recorded, so the person reading the desk sees it
                     * even though the bot missed its turn.
                     */
                    $refused = $exception->getMessage();

                    report($exception);
                }
            }

            /*
             * Saved after the messages have been handed on, never before: a
             * crash between the two is a redelivery the router recognises and
             * drops, while the reverse order is a question nobody ever sees.
             *
             * Saved after a refusal too, and deliberately: an update that
             * cannot be handled would otherwise be fetched for ever, and the
             * questions behind it in the queue would never arrive at all.
             */
            $connection->forceFill([
                'poll_cursor' => $batch->cursor ?? $connection->poll_cursor,
                'last_error' => $refused,
                'last_event_at' => $batch->messages === [] ? $connection->last_event_at : now(),
            ])->save();

            if ($refused !== null) {
                $this->components->error($connection->channel.': '.$refused);
            }

            if ($batch->messages !== []) {
                $this->components->info(sprintf(
                    '%s: %d message(s).',
                    $connection->channel,
                    count($batch->messages),
                ));
            }
        }

        return $asked;
    }

    /**
     * @return iterable<int, ChannelConnection>
     */
    protected function connections(): iterable
    {
        $query = ChannelConnection::query()->polled();

        if (filled($this->option('channel'))) {
            $query->where('channel', $this->option('channel'));
        }

        return $query->get()->all();
    }

    /**
     * Writes the reason where the person setting the channel up will see it.
     *
     * On the connection rather than only in the log, because the screen that
     * asks "is this working" is the bot's Channels tab, and a reason that lives
     * in a file on the server answers the question for nobody who is looking.
     */
    protected function note(ChannelConnection $connection, string $reason): void
    {
        $connection->forceFill(['last_error' => $reason])->save();

        $this->components->error($connection->channel.': '.$reason);
    }
}
