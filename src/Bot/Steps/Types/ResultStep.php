<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Ends a scenario and hands a value back to whoever called it.
 *
 * The value goes to the calling scenario, not to the visitor. A called scenario
 * that wanted to say something already said it; this node answers the caller's
 * question, and the caller decides whether any of it is worth repeating out
 * loud.
 *
 * Reaching it in a scenario that nobody called simply ends the conversation.
 * That is not a fallback so much as a requirement: the same scenario has to be
 * runnable on its own and callable from elsewhere, and forcing an author to
 * maintain two endings for the two cases is how the two endings diverge.
 */
final class ResultStep extends StepType
{
    public static function key(): string
    {
        return 'result';
    }

    public function label(): string
    {
        return 'Return a result';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public function color(): string
    {
        return '#0d9488';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::None;
    }

    public function ends(): bool
    {
        return true;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::text('value')
                ->label('Value to return')
                ->hint('Supports {{variables}}. Leave empty to return nothing.'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $value = $context->filled('value');

        return StepResult::returns($value === '' ? null : $value)
            ->traced(RunStep::RETURNED, ['value' => $value === '' ? null : $value]);
    }
}
