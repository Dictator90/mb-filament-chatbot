<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Scenario;

/**
 * Active bots with nothing to say.
 *
 * A bot with no active scenario is not switched off — it accepts messages, opens
 * conversations and answers none of them. The visitor writes into a widget that
 * works and gets silence, which reads as the company ignoring them rather than
 * as a configuration nobody finished. It happens after a scenario is
 * deactivated to fix something and not switched back on.
 *
 * Scenarios attached to no bot count for every bot, because that is exactly how
 * the runtime picks them: a shared fallback is what most installations rely on,
 * and a check that ignored it would warn about every correctly configured bot
 * they have.
 */
final class BotScenarioCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'bots';
    }

    public function title(): string
    {
        return 'Bots';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $bots = Bot::query()->active()->orderBy('slug')->get();

        if ($bots->isEmpty()) {
            return [DoctorFinding::warn(
                'No bot is active.',
                'Create one in the panel, or run php artisan chatbot:demo-data to get a working example.',
            )];
        }

        $silent = [];

        foreach ($bots as $bot) {
            $answers = Scenario::query()->active()->forBot($bot)->exists();

            if (! $answers) {
                $silent[] = (string) $bot->slug;
            }
        }

        if ($silent !== []) {
            return [DoctorFinding::warn(
                count($silent).' of '.$bots->count().' active bots have no active scenario.',
                'They accept messages and answer none of them, which the visitor reads as being ignored. Switch a scenario on or switch the bot off.',
                $silent,
            )];
        }

        return [DoctorFinding::pass('Every active bot has at least one active scenario.')];
    }
}
