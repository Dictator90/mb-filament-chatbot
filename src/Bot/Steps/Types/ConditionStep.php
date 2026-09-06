<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Conditions\ConditionEvaluator;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Branches on what is already known, without asking anything.
 *
 * Branching lives in the node's options here, exactly as it does for a question
 * and for a keyword match. The three look different on screen — a button, a list
 * of words, a comparison — and are the same thing underneath: a label plus the
 * key of the next node. One way of branching instead of three is why the canvas
 * can draw outputs for all of them with one piece of code, and why an author who
 * has understood one has understood the others.
 *
 * The first matching branch wins. Not the best match, not the most specific: the
 * first, in the order the author put them in. Any other rule would make the
 * order on screen not mean what it looks like it means.
 */
final class ConditionStep extends StepType
{
    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public static function key(): string
    {
        return 'condition';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.condition.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public function color(): string
    {
        return '#f59e0b';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::OptionsElse;
    }

    public function isBasic(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'jump', 'ask', 'handoff'];
    }

    public function run(StepContext $context): StepResult
    {
        foreach ($context->step->choices() as $index => $choice) {
            $when = (array) ($choice['when'] ?? []);

            if ($when === []) {
                continue;
            }

            if ($this->evaluator->evaluate($when, $context->conversation, $context->runtime)) {
                return StepResult::goto($choice['next'])->traced(RunStep::BRANCHED, [
                    'branch' => $choice['label'],
                    'field' => $when['field'] ?? null,
                    'index' => $index,
                ]);
            }
        }

        return StepResult::second()->traced(RunStep::BRANCHED, ['branch' => null]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        foreach ($step->choices() as $choice) {
            $when = (array) ($choice['when'] ?? []);

            if ($when === []) {
                $issues[] = [
                    'severity' => 'notice',
                    'message' => __('chatbot::nodes.steps.condition.issues.branch_without_condition', ['branch' => $choice['label']]),
                ];

                continue;
            }

            if (! $this->evaluator->isUsable($when)) {
                $issues[] = [
                    'severity' => 'notice',
                    'message' => __('chatbot::nodes.steps.condition.issues.branch_unusable', ['branch' => $choice['label']]),
                ];
            }
        }

        return $issues;
    }
}
