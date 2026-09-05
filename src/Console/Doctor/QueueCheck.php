<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Bot\Steps\Types\DelayStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Whether anything in this installation needs a queue that is not there.
 *
 * The sync driver is not broken, it is a default — and a perfectly good one
 * until a scenario contains a pause. A delay node dispatches its continuation
 * with a delay, and on sync that delay is a sleep inside the request that is
 * answering the visitor: the widget hangs for the length of the pause, and on
 * anything longer than the web server's timeout it never answers at all. The
 * author sees a bot that stopped replying and goes looking at the scenario.
 *
 * A warning rather than a failure, because a host may genuinely intend to run
 * without a worker and have no pauses today. It becomes a real problem only
 * when it meets a node, which is why this check counts nodes rather than
 * reading the setting alone.
 */
final class QueueCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'queue';
    }

    public function title(): string
    {
        return 'Queue';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'sync') {
            return [DoctorFinding::pass("Jobs run on the [{$connection}] connection.")];
        }

        $reasons = [];

        $paused = $this->scenariosWithPauses();

        if ($paused !== []) {
            $reasons[] = 'scenarios with a pause node';
        }

        $channels = $this->deliveredChannels();

        if ($channels !== []) {
            $reasons[] = 'channel delivery ('.implode(', ', $channels).')';
        }

        if ($reasons === []) {
            return [DoctorFinding::pass('Jobs run synchronously, and nothing here needs a worker yet.')];
        }

        return [DoctorFinding::warn(
            'Jobs run synchronously, but this installation has '.implode(' and ', $reasons).'.',
            'Point QUEUE_CONNECTION at a real driver and run a worker. On sync a pause is a sleep inside the visitor\'s own request.',
            $paused,
        )];
    }

    /**
     * @return list<string>
     */
    protected function scenariosWithPauses(): array
    {
        return Scenario::query()
            ->whereIn('id', Step::query()->select('scenario_id')->where('type', DelayStep::key()))
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /**
     * Channels whose messages leave the application.
     *
     * The site channel is excluded because it delivers nothing: the widget
     * polls, so its replies never need a job. Counting it would make every
     * installation warn about a queue it does not need.
     *
     * @return list<string>
     */
    protected function deliveredChannels(): array
    {
        $enabled = [];

        foreach ((array) config('chatbot.channels', []) as $channel => $settings) {
            if ($channel !== 'site' && (bool) ($settings['enabled'] ?? false)) {
                $enabled[] = (string) $channel;
            }
        }

        return $enabled;
    }
}
