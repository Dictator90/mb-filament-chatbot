<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Remembers a value without asking anyone for it.
 *
 * What it is for is marking a branch. The visitor tapped "Find me a trip", and
 * three nodes later the scenario has to know that is what they came for — but
 * the button they tapped is by then only a line in the trace, and a condition
 * cannot read the trace. This node writes interest = tour at the moment the
 * branch is taken, and everything downstream reads a fact instead of trying to
 * reconstruct which way the conversation came.
 *
 * It is also the cheap way to carry a value from a lookup or a calculation into
 * the wording of a later message, where {{name}} substitution picks it up.
 */
final class SetStep extends StepType
{
    public static function key(): string
    {
        return 'set';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.set.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-variable';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'ask'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::text('name')
                ->label(__('chatbot::nodes.steps.set.fields.name.label'))
                ->placeholder(__('chatbot::nodes.steps.set.fields.name.placeholder'))
                ->required()
                ->hint(__('chatbot::nodes.steps.set.fields.name.hint')),

            Field::text('value')
                ->label(__('chatbot::nodes.steps.set.fields.value.label'))
                ->placeholder(__('chatbot::nodes.steps.set.fields.value.placeholder'))
                ->hint(__('chatbot::nodes.steps.set.fields.value.hint')),
        ];
    }

    /**
     * @return list<string>
     */
    public function writes(Step $step): array
    {
        $name = trim((string) $step->setting('name', ''));

        return $name === '' ? [] : [$name];
    }

    public function run(StepContext $context): StepResult
    {
        $name = trim((string) $context->setting('name'));
        $value = $context->filled('value');

        if ($name === '') {
            return StepResult::next()->traced(RunStep::FAILED, [
                'reason' => 'The node has no variable name, so there is nothing to write the value under.',
                'value' => $value,
            ]);
        }

        $context->runtime->store($name, $value);

        return StepResult::next()->traced(RunStep::PASSED, [
            'variable' => $name,
            'value' => $value,
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        if (trim((string) $step->setting('name')) !== '') {
            return [];
        }

        return [[
            'severity' => 'blocking',
            'message' => __('chatbot::nodes.steps.set.issues.no_name'),
        ]];
    }
}
