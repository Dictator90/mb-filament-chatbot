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
 * Hands the conversation to another scenario and does not come back.
 *
 * The difference from a call is the whole reason both exist. A call runs
 * another scenario and returns with its result, so the caller can use what it
 * found; a jump is a change of subject — the visitor asked about a refund in
 * the middle of a booking, and the booking scenario has nothing left to do
 * afterwards. Drawn as one node with a "returns" checkbox, the two read the
 * same on the canvas and behave completely differently, and an author would
 * only discover which one they built by watching a conversation end where they
 * expected it to continue. Two nodes, two shapes, no checkbox.
 *
 * Because it never returns, the node has no exits at all: anything an author
 * drew after it would be unreachable, and a node with an arrow nobody follows
 * is worse than a node with none.
 */
final class JumpStep extends StepType
{
    public static function key(): string
    {
        return 'jump';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.jump.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-top-right-on-square';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::None;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::scenario('scenario')
                ->label(__('chatbot::nodes.steps.jump.fields.scenario.label'))
                ->required()
                ->hint(__('chatbot::nodes.steps.jump.fields.scenario.hint')),
        ];
    }

    /**
     * An unset target ends at a person, not at silence.
     *
     * The alternative is closing the branch, and that is the failure nobody
     * reports: the visitor asked something, the bot went quiet, and the
     * conversation looks answered from the panel. Handing over costs an
     * operator a minute; the quiet version costs a customer.
     */
    public function run(StepContext $context): StepResult
    {
        $slug = $this->target($context->step);

        if ($slug === null) {
            return StepResult::handoff()->traced(RunStep::FAILED, [
                'reason' => 'The node names no scenario to continue in.',
            ]);
        }

        return StepResult::jump($slug)->traced(RunStep::JUMPED, ['scenario' => $slug]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        $slug = $this->target($step);

        return $slug === null ? [] : ['scenarios' => [$slug]];
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        if ($this->target($step) !== null) {
            return [];
        }

        return [[
            'severity' => 'blocking',
            'message' => __('chatbot::nodes.steps.jump.issues.no_scenario'),
        ]];
    }

    private function target(Step $step): ?string
    {
        $slug = trim((string) $step->setting('scenario'));

        return $slug === '' ? null : $slug;
    }
}
