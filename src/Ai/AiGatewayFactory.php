<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

use Closure;
use MB\FilamentChatbot\Ai\Drivers\LaravelAiGateway;
use MB\FilamentChatbot\Ai\Drivers\NullGateway;
use MB\FilamentChatbot\Ai\Drivers\OpenAiGateway;
use MB\FilamentChatbot\Budgets\BudgetedGateway;
use MB\FilamentChatbot\Budgets\BudgetGuard;
use MB\FilamentChatbot\Models\Bot;

/**
 * Builds the gateway named in configuration, and never fails to build one.
 *
 * **An unknown name gives the null gateway rather than an exception.** The name
 * comes from a config file or an environment variable, so getting it wrong is a
 * typo, and a typo must not be able to take down the panel, the queue worker
 * and every conversation at once. Declined instead of thrown, the mistake shows
 * up where it can be read — a run journal full of "no model is configured" —
 * and the bot keeps answering everything that never needed a model.
 *
 * The registry is open so a host can add its own provider without waiting for
 * the package:
 *
 *     app(AiGatewayFactory::class)->extend('anthropic', fn (array $config) => new MyGateway($config));
 *
 * Registered from the host's provider, its config read from the same
 * `chatbot.ai.drivers` array, so a host driver is configured exactly like a
 * built-in one.
 */
class AiGatewayFactory
{
    /** @var array<string, Closure(array<string, mixed>): AiGateway> */
    private array $drivers = [];

    public function __construct(private readonly BudgetGuard $budgets) {}

    /**
     * @param  Closure(array<string, mixed>): AiGateway  $factory
     */
    public function extend(string $name, Closure $factory): static
    {
        $this->drivers[$name] = $factory;

        return $this;
    }

    /**
     * The bare driver for a name, or for the configured default.
     *
     * An empty name means "whatever the installation is set to", which is what
     * a node's blank provider field sends: an author who did not choose a
     * provider wants the one the administrator chose.
     *
     * **Nothing that actually asks a model should build its gateway here.**
     * This one spends without being counted; `guarded()` is the one a
     * conversation uses. What is left for this method is everything that only
     * wants to know whether a provider is configured at all — the doctor, the
     * editor's warnings, the panel's driver list — none of which sends a
     * request.
     */
    public function make(?string $name = null): AiGateway
    {
        if ($this->suppressed()) {
            return new NullGateway;
        }

        $name = trim((string) ($name ?? ''));

        if ($name === '') {
            $name = trim((string) config('chatbot.ai.default', 'null'));
        }

        $config = (array) config('chatbot.ai.drivers.'.$name, []);

        if (isset($this->drivers[$name])) {
            return ($this->drivers[$name])($config);
        }

        return match ($name) {
            'openai' => new OpenAiGateway($config),
            'laravel-ai' => new LaravelAiGateway($config),
            default => new NullGateway,
        };
    }

    /**
     * The gateway a conversation asks: the driver with this month's budget in
     * front of it.
     *
     * The bot is asked for rather than looked up, because the budget belongs to
     * the bot holding the conversation and this class has no conversation. A
     * null bot is not an oversight — a call made outside any bot still costs
     * money and is charged to the installation's own row, which is the row
     * nobody thinks to look at until it is the only spending there is.
     */
    public function guarded(?Bot $bot = null, ?string $name = null): AiGateway
    {
        return new BudgetedGateway($this->make($name), $this->budgets, $bot);
    }

    /**
     * Whether something has switched models off for the duration of its work.
     *
     * Read here rather than at each call site because a node carries its own
     * provider setting: a rehearsal that only cleared the installation default
     * would still reach a provider through every node that names one, and would
     * do it on a nightly schedule nobody is watching. {@see WithoutModel} is
     * what sets it.
     */
    private function suppressed(): bool
    {
        return (bool) config('chatbot.ai.suppressed', false);
    }

    /**
     * The driver names an author may pick from in a node's settings.
     *
     * Read from configuration rather than from a hard-coded list, because a
     * host driver registered through extend() is as choosable as a built-in one
     * and an author has no way to know which is which.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = array_keys((array) config('chatbot.ai.drivers', []));

        foreach (array_keys($this->drivers) as $name) {
            $names[] = $name;
        }

        $names = array_values(array_unique(array_map(static fn (mixed $name): string => (string) $name, $names)));

        return array_combine($names, $names);
    }
}
