<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Step;

/**
 * Asks a question with ready answers, and routes on the one that comes back.
 *
 * Each option names its own next node, so the branch is drawn on the canvas
 * rather than hidden in a condition somewhere below the question.
 */
final class AskStep extends StepType
{
    public static function key(): string
    {
        return 'ask';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.ask.label');
    }

    /**
     * The wording used to list the options again when nothing matched.
     *
     * A method rather than a constant now that it is translated: a constant is
     * resolved once, when the class is loaded, and would freeze whichever
     * locale happened to be active on the first request a worker served for
     * every conversation that worker handled afterwards.
     */
    private function retryDefault(): string
    {
        return __('chatbot::nodes.steps.ask.retry');
    }

    public function icon(): string
    {
        return 'heroicon-o-queue-list';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::OptionsElse;
    }

    public function isBasic(): bool
    {
        return true;
    }

    public function speaks(): bool
    {
        return true;
    }

    public function waitsForReply(): bool
    {
        return true;
    }

    /**
     * The buttons this question offers, taken from the same list the answer is
     * matched against — so what the visitor is shown and what the bot will
     * accept cannot be two different sets.
     *
     * @return list<string>
     */
    public function choiceLabels(Step $step): array
    {
        return array_values(array_map(
            static fn (array $choice): string => (string) $choice['label'],
            $step->choices(),
        ));
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['set', 'say', 'form', 'jump'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::text('retry')
                ->label(__('chatbot::nodes.steps.ask.fields.retry.label'))
                ->default($this->retryDefault())
                ->hint(__('chatbot::nodes.steps.ask.fields.retry.hint')),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        return StepResult::wait()->traced(RunStep::WAITING);
    }

    /**
     * Reads the answer and takes the option it names.
     *
     * The fallback exit is what keeps this node from becoming a trap. Without
     * one, a visitor who typed a sentence instead of tapping a button got the
     * same question with the same buttons back, every time, for as long as they
     * kept writing — the bot had nowhere else to go, and the only way out of the
     * loop was to guess the word "operator". So: nothing matched and a fallback
     * exists, the conversation leaves through it rather than asking again.
     */
    public function resume(StepContext $context, string $answer): StepResult
    {
        $choices = $context->step->choices();

        if ($choices === []) {
            return StepResult::next()->traced(RunStep::ANSWERED, ['answer' => $answer]);
        }

        $choice = $this->match($choices, $answer);

        if ($choice !== null) {
            return StepResult::goto($choice['next'])
                ->traced(RunStep::BRANCHED, ['choice' => $choice['label']]);
        }

        $fallback = $context->step->secondTarget();

        if ($fallback !== null) {
            return StepResult::goto($fallback)
                ->traced(RunStep::BRANCHED, ['choice' => null, 'answer' => $answer]);
        }

        $context->runtime->say($this->retryMessage($context, $choices));

        return StepResult::wait()
            ->traced(RunStep::UNKNOWN, ['answer' => $answer])
            ->spoken();
    }

    /**
     * Finds the option an answer names, or null.
     *
     * Exact labels first, then ordinals, then prefixes, and the order between
     * the three passes matters. A prefix tried per option instead would let an
     * early "No" swallow an answer meant for a later "November" — and worse, the
     * option that wins would depend on the order the author happened to drag the
     * buttons into.
     *
     * @param  list<array{label: string, next: string, words: list<string>, when: array<string, mixed>}>  $choices
     * @return array{label: string, next: string, words: list<string>, when: array<string, mixed>}|null
     */
    private function match(array $choices, string $answer): ?array
    {
        $answer = $this->normalise($answer);

        if ($answer === '') {
            return null;
        }

        foreach ($choices as $choice) {
            if ($this->normalise($choice['label']) === $answer) {
                return $choice;
            }
        }

        $ordinal = $this->ordinal($choices, $answer);

        if ($ordinal !== null) {
            return $ordinal;
        }

        foreach ($choices as $choice) {
            if (str_starts_with($this->normalise($choice['label']), $answer)) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The option a bare number refers to.
     *
     * Not every channel draws buttons — e-mail, SMS and the plainest webhooks
     * receive the options as a numbered list and nothing else — so "2" is a
     * real answer there rather than a stray digit, and the node that refused it
     * would be unusable on exactly those channels.
     *
     * @param  list<array{label: string, next: string, words: list<string>, when: array<string, mixed>}>  $choices
     * @return array{label: string, next: string, words: list<string>, when: array<string, mixed>}|null
     */
    private function ordinal(array $choices, string $answer): ?array
    {
        if (! ctype_digit($answer)) {
            return null;
        }

        $position = (int) $answer;

        return $choices[$position - 1] ?? null;
    }

    /**
     * @param  list<array{label: string, next: string, words: list<string>, when: array<string, mixed>}>  $choices
     */
    private function retryMessage(StepContext $context, array $choices): string
    {
        $lead = trim($context->filled('retry', $this->retryDefault()));

        if ($lead === '') {
            $lead = $this->retryDefault();
        }

        $lines = [$lead];

        foreach ($choices as $position => $choice) {
            $lines[] = ($position + 1).'. '.$choice['label'];
        }

        return implode(PHP_EOL, $lines);
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
