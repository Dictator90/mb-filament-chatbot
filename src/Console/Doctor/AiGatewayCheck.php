<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use BackedEnum;
use MB\FilamentChatbot\Ai\AiPrompt;
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

        $unconfigured = [];
        $refused = [];
        $checked = 0;

        foreach (Bot::query()->active()->orderBy('slug')->get() as $bot) {
            $driver = $this->driverFor($bot);

            if ($driver === null) {
                continue;
            }

            $checked++;

            if (! $this->isConfigured($factory, $driver)) {
                $unconfigured[] = "{$bot->slug} ({$driver})";

                continue;
            }

            $complaint = $this->offline ? null : $this->refusal($factory, $driver);

            if ($complaint !== null) {
                $refused[] = "{$bot->slug} ({$driver}): {$complaint}";
            }
        }

        if ($checked === 0) {
            return [DoctorFinding::skipped('No active bot is configured to use a model.')];
        }

        $findings = [];

        if ($unconfigured !== []) {
            $findings[] = DoctorFinding::warn(
                count($unconfigured).' of '.$checked.' bots name a driver that has no key or no address.',
                'Fill in the API key and base URL for that driver. Until it answers, every AI node takes its "could not" branch and the bot simply gets worse.',
                $unconfigured,
            );
        }

        if ($refused !== []) {
            $findings[] = DoctorFinding::warn(
                count($refused).' of '.$checked.' bots point at a gateway that refused the request.',
                'The settings are filled in and the provider still said no. The reason it gave is above — a retired model name, a key without access, or a region the provider does not serve are the usual three.',
                $refused,
            );
        }

        if ($findings !== []) {
            return $findings;
        }

        return [DoctorFinding::pass($this->offline
            ? "All {$checked} bots using a model have a key and an address. Nothing was asked of the provider."
            : "All {$checked} bots using a model got an answer from their gateway.")];
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
     * A gateway that throws while being asked whether it is configured is not
     * configured. Letting that escape would report a misconfigured provider as a
     * crashed doctor.
     */
    protected function isConfigured(object $factory, string $driver): bool
    {
        try {
            $gateway = $factory->make($driver);

            return ! method_exists($gateway, 'isAvailable') || (bool) $gateway->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Asks the provider something trivial and reports what it complained about.
     *
     * The doctor asks for real because `isAvailable()` cannot. That method is
     * consulted before every single AI node, so it is deliberately a check of
     * two strings and never a round trip — and reused here it made this report
     * claim a gateway "can be reached" on the strength of a key being non-empty.
     * It said exactly that about an installation whose provider answered every
     * request with "user location is not supported": the doctor passed, and the
     * bot quietly took its "could not" branch for every question anybody asked
     * it. A key that is present and a key that is accepted are different facts,
     * and this is the one command whose whole purpose is to tell them apart.
     *
     * One tiny question per bot, and only when the doctor was allowed to leave
     * the machine — `--offline` says the settings look filled in and nothing
     * more, which is what a deployment pipeline with no outbound access wants
     * to hear.
     */
    protected function refusal(object $factory, string $driver): ?string
    {
        try {
            $gateway = $factory->make($driver);

            if (! method_exists($gateway, 'ask')) {
                return null;
            }

            $reply = $gateway->ask(new AiPrompt(
                instruction: 'Answer with the single word OK.',
                question: 'Are you there?',
                maxTokens: 5,
            ));

            if (! method_exists($reply, 'ok') || $reply->ok() === true) {
                return null;
            }

            return $this->words($reply);
        } catch (Throwable $e) {
            return $this->shorten($e->getMessage());
        }
    }

    /**
     * What the provider said, in as few words as fit on one line of a report.
     *
     * The provider's own sentence first, and the package's failure category only
     * when there is none. "This model is no longer available to new users" sends
     * somebody to the right setting in seconds; "the gateway declined" sends them
     * to read this source.
     */
    protected function words(object $reply): string
    {
        $said = method_exists($reply, 'error') ? (string) $reply->error() : '';

        if ($said === '' && method_exists($reply, 'failure')) {
            $failure = $reply->failure();
            $said = $failure instanceof BackedEnum ? (string) $failure->value : '';
        }

        return $this->shorten($said === '' ? 'declined without saying why' : $said);
    }

    protected function shorten(string $said): string
    {
        $said = trim(preg_replace('/\s+/u', ' ', $said) ?? $said);

        return mb_strlen($said) > 140 ? mb_substr($said, 0, 137).'…' : $said;
    }
}
