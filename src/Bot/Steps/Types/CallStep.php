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
 * Runs another scenario and comes back with its answer.
 *
 * The difference from a jump is the coming back, and it is the whole reason
 * this node exists. Work that several scenarios need — identifying the visitor,
 * collecting a phone number, checking whether an order exists — was previously
 * copied into each of them, and copies drift: one gets a fix, the others keep
 * asking the old question for another year.
 *
 * Two outputs. The first is taken when the called scenario returns. The second
 * is for the cases where it cannot even be started — no such scenario, or calls
 * nested deeper than the engine allows — because a scenario that silently did
 * nothing here would look identical to one that returned an empty answer.
 */
final class CallStep extends StepType
{
    public static function key(): string
    {
        return 'call';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.call.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-uturn-right';
    }

    public function color(): string
    {
        return '#0d9488';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Pair;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function pairLabels(): array
    {
        return array_values((array) __('chatbot::nodes.steps.call.exits'));
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['condition', 'say', 'form', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::scenario('scenario')
                ->label(__('chatbot::nodes.steps.call.fields.scenario.label'))
                ->required(),
            Field::text('into')
                ->label(__('chatbot::nodes.steps.call.fields.into.label'))
                ->hint(__('chatbot::nodes.steps.call.fields.into.hint')),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $slug = trim((string) $context->setting('scenario', ''));

        if ($slug === '') {
            return StepResult::second()->traced(RunStep::FAILED, ['reason' => 'no scenario chosen']);
        }

        return StepResult::call($slug)->traced(RunStep::CALLED, [
            'scenario' => $slug,
            'into' => $context->setting('into') ?: null,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        $slug = trim((string) $step->setting('scenario', ''));

        return $slug === '' ? [] : ['scenarios' => [$slug]];
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        if (trim((string) $step->setting('scenario', '')) === '') {
            return [['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.call.issues.no_scenario')]];
        }

        return [];
    }
}
