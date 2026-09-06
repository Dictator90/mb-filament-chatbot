<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Splits the walk on words the visitor has already written.
 *
 * Asks nothing and says nothing: it reads the message that brought the
 * conversation here. That is what separates it from a question — a question
 * interrupts to collect an answer, this one sorts an answer that already
 * exists, which is how a scenario reacts to "I want to cancel" without first
 * asking what the visitor wants.
 */
final class KeywordStep extends StepType
{
    public static function key(): string
    {
        return 'keyword';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.keyword.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-magnifying-glass';
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
        return false;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'ask', 'set', 'handoff'];
    }

    public function run(StepContext $context): StepResult
    {
        $said = (string) $context->said;

        foreach ($context->step->choices() as $choice) {
            foreach ($choice['words'] as $word) {
                if ($this->contains($said, $word)) {
                    return StepResult::goto($choice['next'])
                        ->traced(RunStep::BRANCHED, ['word' => $word]);
                }
            }
        }

        return StepResult::goto($context->step->secondTarget())
            ->traced(RunStep::BRANCHED, ['word' => null]);
    }

    /**
     * Whether a message contains a word, as a word.
     *
     * A plain substring search is what this used to be, and it sends people
     * down the wrong branch quietly: "no" is inside "notebook", "tour" is
     * inside "detour", and the visitor asking about their notebook order was
     * routed to the refusal branch with nothing in the transcript to explain
     * why. The lookarounds are spelled out instead of relying on \b because the
     * boundary has to hold for Cyrillic as well, and the class of letters that
     * counts as a word character is exactly the thing \b leaves to the engine's
     * configuration.
     */
    private function contains(string $haystack, string $needle): bool
    {
        if (trim($needle) === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote(trim($needle), '/').'(?![\p{L}\p{N}_])/iu';

        return preg_match($pattern, $haystack) === 1;
    }
}
