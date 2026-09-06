<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Routing;

use Illuminate\Support\Collection;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;

/**
 * Asks a model which scenario fits a message that no keyword claimed.
 *
 * Four rules, and each of them is here because the obvious version of this
 * class is worse.
 *
 * **It runs last, never first.** Routing by words is free, instant, works with
 * the provider unreachable, and is visible on the canvas — an author can read
 * the words off the start node and know why a message landed where it did. A
 * model asked first would take that away from every conversation, including the
 * ones a single keyword already answered correctly, and would put a network
 * round trip in front of the busiest path in the package.
 *
 * **Every failure is silence, not an error.** No driver, no key, a refusal, a
 * timeout, an unreadable reply, a month's budget already spent: all of them
 * return null, and the picker carries on to its fallback exactly as it would
 * have without this class. The bot's behaviour with the provider down is its
 * behaviour with the provider absent, which is the behaviour the suite runs
 * against by default. The budget is the one worth naming: unlike an outage it
 * does not pass, so it lands on every unmatched message for the rest of the
 * month, and routing by words has to go on working underneath it that whole
 * time.
 *
 * **The choice is from the given list only.** A model naming a scenario that
 * was not offered has invented it — or has named one deliberately withheld
 * because it does not cover this channel or this audience — and both are
 * treated as "did not choose". Looking the name up globally instead would let a
 * hallucination route a customer into a scenario nobody made reachable.
 *
 * **A scenario with no invocation contract is not offered at all.** The
 * contract — what the scenario is for, when it applies, when it does not, and a
 * few example messages — is the author saying this scenario may be chosen by a
 * model and on what grounds. Offering every active scenario instead would make
 * the model's list grow silently with the installation, and a scenario written
 * for a keyword nobody types would start catching live conversations.
 */
class AiScenarioRouter implements ScenarioRouter
{
    public function __construct(private readonly AiGatewayFactory $gateways) {}

    /**
     * @param  Collection<int, Scenario>  $scenarios
     */
    public function route(Conversation $conversation, ?string $said, Collection $scenarios): ?Scenario
    {
        $said = trim((string) $said);

        if ($said === '' || ! (bool) config('chatbot.ai.routing.enabled', false)) {
            return null;
        }

        $offered = $this->offered($scenarios);

        if ($offered->count() < 1) {
            return null;
        }

        $gateway = $this->gateways->guarded($conversation->bot);

        if (! $gateway->isAvailable()) {
            return null;
        }

        $reply = $gateway->ask(new AiPrompt(
            instruction: (string) config('chatbot.ai.routing.instruction', ''),
            question: $said,
            context: $this->contractsOf($offered),
            schema: ['scenario' => 'the key of the scenario that fits, or null when none of them do'],
        ));

        if (! $reply->ok()) {
            return null;
        }

        $chosen = $reply->data()['scenario'] ?? null;

        if (! is_string($chosen) || trim($chosen) === '') {
            return null;
        }

        return $offered->first(fn (Scenario $scenario): bool => $scenario->slug === trim($chosen));
    }

    /**
     * The scenarios a model may choose between.
     *
     * Capped, because the list is sent on every unmatched message and the whole
     * of it is paid for per token. An installation with two hundred scenarios
     * would otherwise send two hundred contracts to decide one message, and the
     * request would be slower and more expensive than the conversation it is
     * routing.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @return Collection<int, Scenario>
     */
    protected function offered(Collection $scenarios): Collection
    {
        return $scenarios
            ->filter(fn (Scenario $scenario): bool => $this->purposeOf($scenario) !== '')
            ->take(max(1, (int) config('chatbot.ai.routing.candidates', 12)))
            ->values();
    }

    /**
     * Each scenario's invocation contract, as one labelled fragment.
     *
     * The slug is the fragment key so that the model names back something this
     * side can verify against the list it sent, rather than a title that two
     * scenarios could share.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @return list<array{key: string, title: string, text: string}>
     */
    protected function contractsOf(Collection $scenarios): array
    {
        return $scenarios->map(function (Scenario $scenario): array {
            $invocation = (array) ($scenario->invocation ?? []);

            $lines = ['Purpose: '.$this->purposeOf($scenario)];

            if (trim((string) ($invocation['useWhen'] ?? '')) !== '') {
                $lines[] = 'Use when: '.trim((string) $invocation['useWhen']);
            }

            $avoid = $this->linesOf($invocation['doNotUseWhen'] ?? []);

            if ($avoid !== []) {
                $lines[] = 'Do not use when: '.implode('; ', $avoid);
            }

            $examples = $this->linesOf($invocation['examples'] ?? []);

            if ($examples !== []) {
                $lines[] = 'Example messages: '.implode(' | ', $examples);
            }

            return [
                'key' => (string) $scenario->slug,
                'title' => (string) $scenario->name,
                'text' => implode("\n", $lines),
            ];
        })->all();
    }

    protected function purposeOf(Scenario $scenario): string
    {
        $invocation = (array) ($scenario->invocation ?? []);

        return trim((string) ($invocation['purpose'] ?? ''));
    }

    /**
     * @return list<string>
     */
    protected function linesOf(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) (is_scalar($line) ? $line : '')), $value),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
