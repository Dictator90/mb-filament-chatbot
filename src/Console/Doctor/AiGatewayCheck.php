<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Models\Bot;
use Throwable;

/**
 * Can each bot that is meant to use a model actually reach one.
 *
 * An unreachable gateway does not break a bot, it flattens it: every AI node
 * takes its "could not" exit, the scenario keeps working, and the answers get
 * quietly worse. That is the whole reason the package treats an outage as a
 * branch rather than an error — and the reason it has to be said out loud
 * somewhere, because nothing else will. A missing API key looks exactly like a
 * bot whose scenarios were written badly.
 *
 * A warning rather than a failure for the same reason: the installation works.
 *
 * The gateway factory is named as a string rather than imported. A `use` of a
 * class the doctor merely hopes to find is how the doctor becomes the thing
 * that cannot run — and this command has to survive exactly the installations
 * that are broken.
 */
final class AiGatewayCheck extends DoctorCheck
{
    protected const string FACTORY = 'MB\\FilamentChatbot\\Ai\\AiGatewayFactory';

    public static function key(): string
    {
        return 'ai';
    }

    public function title(): string
    {
        return 'AI gateways';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        if (! class_exists(self::FACTORY)) {
            return [DoctorFinding::skipped('No AI subsystem is installed, so nothing asks a model anything.')];
        }

        $factory = app(self::FACTORY);

        if (! method_exists($factory, 'make')) {
            return [DoctorFinding::skipped('The AI gateway factory does not expose drivers to check.')];
        }

        $unavailable = [];
        $checked = 0;

        foreach (Bot::query()->active()->orderBy('slug')->get() as $bot) {
            $driver = $this->driverFor($bot);

            if ($driver === null) {
                continue;
            }

            $checked++;

            if (! $this->isAvailable($factory, $driver)) {
                $unavailable[] = "{$bot->slug} ({$driver})";
            }
        }

        if ($checked === 0) {
            return [DoctorFinding::skipped('No active bot is configured to use a model.')];
        }

        if ($unavailable !== []) {
            return [DoctorFinding::warn(
                count($unavailable).' of '.$checked.' bots point at a gateway that is not available.',
                'Check the API key and base URL for that driver. Until it answers, every AI node takes its "could not" branch and the bot simply gets worse.',
                $unavailable,
            )];
        }

        return [DoctorFinding::pass("All {$checked} bots using a model can reach their gateway.")];
    }

    /**
     * The driver a bot would actually use, or null when it uses none.
     *
     * The bot's own column wins over the installation default, matching what
     * the runtime does. Reading only the default would clear a bot that names a
     * driver nobody configured — the one case worth catching.
     */
    protected function driverFor(Bot $bot): ?string
    {
        $driver = (string) ($bot->ai_driver ?: config('chatbot.ai.default', 'null'));

        return $driver === '' || $driver === 'null' ? null : $driver;
    }

    /**
     * A gateway that throws while being asked whether it is available is not
     * available. Letting that escape would report a misconfigured provider as a
     * crashed doctor.
     */
    protected function isAvailable(object $factory, string $driver): bool
    {
        try {
            $gateway = $factory->make($driver);

            return ! method_exists($gateway, 'isAvailable') || (bool) $gateway->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }
}
