<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\Concerns\WritesRecordVariables;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Capabilities\CapabilityGateway;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Does one of the things the host application registered as a capability.
 *
 * The other half of teaching a bot about an application. A data resource lets a
 * scenario read records without anybody writing code; this node covers
 * everything a read cannot do — award the points, open the ticket, cancel the
 * booking, work out a price from rules that live in the host.
 *
 * The node itself knows nothing about any particular action, and that is what
 * makes it worth having: the author picks a registered action from a list, the
 * call form is drawn from the descriptors that action declared, and the result
 * lands in variables under a name of the author's choosing.
 *
 * Two exits. The second one means the action did not happen — unregistered,
 * refused for a bad input, or thrown out of. All three are the same decision
 * for a scenario (say something honest, fetch a person) and are told apart in
 * the trace rather than by a third arrow.
 */
final class ActionStep extends StepType
{
    use WritesRecordVariables;

    public function __construct(
        private readonly ChatbotRegistry $registry,
        private readonly CapabilityGateway $gateway,
    ) {}

    public static function key(): string
    {
        return 'action';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.action.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-bolt';
    }

    public function color(): string
    {
        return '#7c3aed';
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
        return array_values((array) __('chatbot::nodes.steps.action.exits'));
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::capability('capability')
                ->label(__('chatbot::nodes.steps.action.fields.capability.label'))
                ->required()
                ->options(fn (): array => $this->options()),

            Field::keyValue('input')
                ->label(__('chatbot::nodes.steps.action.fields.input.label'))
                ->hint(__('chatbot::nodes.steps.action.fields.input.hint')),

            Field::text('into')
                ->label(__('chatbot::nodes.steps.action.fields.into.label'))
                ->placeholder(__('chatbot::nodes.steps.action.fields.into.placeholder'))
                ->hint(__('chatbot::nodes.steps.action.fields.into.hint')),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $key = trim((string) $context->setting('capability', ''));
        $action = $key === '' ? null : $this->registry->capability($key);

        if ($action === null) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'capability' => $key,
                'reason' => $key === ''
                    ? 'The node does not say which action to run.'
                    : 'No action is registered under this key.',
            ]);
        }

        $result = $this->gateway->run($action, $this->payload($context), $context);

        if (! $result->ok()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'capability' => $action->reference(),
                'reason' => $result->error(),
            ]);
        }

        $into = trim((string) $context->setting('into', ''));

        if ($into !== '') {
            $this->rememberResult($context, $into, $result->data());
        }

        return StepResult::next()->traced(RunStep::PASSED, [
            'capability' => $action->reference(),
            'effect' => $action->effect->value,
            'into' => $into === '' ? null : $into,
        ]);
    }

    /**
     * The action a released version of this scenario is bound to, version and
     * all.
     *
     * The version is the whole point of the pin. Pinned by key alone, an action
     * whose meaning was deliberately changed — points that now cost money, an
     * input that became required — would slide under a released scenario with
     * nothing to show for it, and the first sign would be the wrong thing
     * happening to a customer. With the version in the pin the release can say
     * which actions moved beneath it.
     *
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        $key = trim((string) $step->setting('capability', ''));

        if ($key === '') {
            return [];
        }

        $action = $this->registry->capability($key);

        return ['capabilities' => [$action?->reference() ?? $key.'@?']];
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $key = trim((string) $step->setting('capability', ''));

        if ($key === '') {
            return [[
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.action.issues.no_action'),
            ]];
        }

        $action = $this->registry->capability($key);

        if ($action === null) {
            return [[
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.action.issues.unknown_action', ['key' => $key]),
            ]];
        }

        if ($action->effect !== SideEffect::Write) {
            return [];
        }

        return [[
            'severity' => 'notice',
            'message' => __('chatbot::nodes.steps.action.issues.unconfirmed_write', ['action' => $action->label]),
        ]];
    }

    /**
     * The values to call the action with, variables substituted.
     *
     * Substitution happens here and not inside the gateway, because the gateway
     * is what tells a good payload from a bad one and it has to judge the
     * values that will genuinely be passed. Filled afterwards, an input that
     * looked present as "{{booking_id}}" would arrive at the handler as an
     * empty string whenever the conversation never learned one — through a
     * check that had already said the payload was complete.
     *
     * @return array<string, mixed>
     */
    private function payload(StepContext $context): array
    {
        $payload = [];

        foreach ((array) $context->setting('input', []) as $name => $value) {
            $name = trim((string) $name);

            if ($name === '' || is_array($value)) {
                continue;
            }

            $payload[$name] = $context->runtime->fill((string) $value);
        }

        return $payload;
    }

    /**
     * Spreads what the action returned over prefixed variable names.
     *
     * The same convention the lookup and the picker write records under
     * ({@see WritesRecordVariables}), so an author who has read one message
     * template can write the next one. A returned value that is not a scalar —
     * a nested array, an object — is rendered as JSON rather than dropped: a
     * name that quietly does not exist prints as a gap in a sentence, and the
     * author has nothing to go on.
     *
     * @param  array<string, mixed>  $data
     */
    private function rememberResult(StepContext $context, string $into, array $data): void
    {
        $row = [];

        foreach ($data as $name => $value) {
            $row[(string) $name] = is_scalar($value) || $value === null
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $this->rememberRecord($context->runtime, $into, $row);
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        $options = [];

        foreach ($this->registry->capabilities() as $key => $action) {
            $options[$key] = $this->describe($action);
        }

        return $options;
    }

    /**
     * The one line an author picks an action by.
     *
     * The label alone is not enough on a list that mixes reads and writes: an
     * author choosing between "Cancel booking" and "Check cancellation fee"
     * cannot tell from those words which one is the one that cannot be taken
     * back.
     */
    private function describe(CapabilityAction $action): string
    {
        $suffix = $action->effect === SideEffect::Write ? ' (changes data)' : '';

        return $action->label.$suffix;
    }
}
