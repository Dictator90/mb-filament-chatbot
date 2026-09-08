<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\Redactor;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\Concerns\WritesRecordVariables;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Pulls named values out of what somebody wrote, and does nothing else.
 *
 * The useful half of a language model with none of the dangerous half. A
 * sentence like "we are two adults and a child, flying from Rostov around the
 * tenth of June" holds four values that no pattern reliably finds, and this
 * node turns them into variables the rest of the scenario can branch on, ask
 * about and send.
 *
 * **What it may not do is decide anything.** It does not choose the next node,
 * it does not call a host action, it does not compose a word the visitor will
 * read. That is a boundary, not a missing feature. A model allowed to choose
 * the route takes a different one on Tuesday for the same sentence it saw on
 * Monday — and the author, asked by a customer why the conversation went the
 * way it did, has no answer and no way to get one. Extraction is checkable: the
 * values land in named variables, the journal shows them, and the branching
 * next to it is a condition somebody wrote and can read back. Keep those apart
 * and a scenario stays explainable with a model in it.
 *
 * Two exits. The second means no values came back — no model configured, the
 * provider refused, the month's budget spent, the answer unreadable — and
 * **nothing is written on that path**. Writing partial or invented values would
 * be worse than not extracting at all: the conversation would carry on
 * confidently against a passenger count nobody said.
 *
 * **This is the one node that does not redact what the visitor wrote**, and the
 * exception is deliberate rather than overlooked. Everywhere else a message
 * leaves the machine — the knowledge answer, the scenario router — a phone
 * number, a reference and a passport are replaced first
 * ({@see Redactor}), because none of those paths need
 * them. Here they are frequently the whole point: "pull the booking reference
 * out of this sentence" is what the node is for, and a redacted question would
 * hand the model `[number]` and give back an empty extraction that looks
 * exactly like a provider being down.
 *
 * So the choice belongs to whoever draws the scenario, and it is visible where
 * they make it: the schema on this node is the list of what is being asked for.
 */
final class AiTaskStep extends StepType
{
    use WritesRecordVariables;

    public function __construct(private readonly AiGatewayFactory $gateways) {}

    public static function key(): string
    {
        return 'ai_task';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.ai_task.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-beaker';
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
        return array_values((array) __('chatbot::nodes.steps.ai_task.exits'));
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
        return ['condition', 'say', 'collect', 'query'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::textarea('instruction')
                ->label(__('chatbot::nodes.steps.ai_task.fields.instruction.label'))
                ->rows(4)
                ->required()
                ->placeholder(__('chatbot::nodes.steps.ai_task.fields.instruction.placeholder'))
                ->hint(__('chatbot::nodes.steps.ai_task.fields.instruction.hint')),

            Field::text('input')
                ->label(__('chatbot::nodes.steps.ai_task.fields.input.label'))
                ->placeholder('{{last_message}}')
                ->hint(__('chatbot::nodes.steps.ai_task.fields.input.hint')),

            Field::list('fields')
                ->label(__('chatbot::nodes.steps.ai_task.fields.fields.label'))
                ->hint(__('chatbot::nodes.steps.ai_task.fields.fields.hint')),

            Field::text('into')
                ->label(__('chatbot::nodes.steps.ai_task.fields.into.label'))
                ->required()
                ->placeholder('trip')
                ->hint(__('chatbot::nodes.steps.ai_task.fields.into.hint')),

            Field::select('provider')
                ->label(__('chatbot::nodes.steps.ai_task.fields.provider.label'))
                ->default('')
                ->options(fn (): array => ['' => __('chatbot::nodes.steps.ai_task.fields.provider.default_option')] + $this->gateways->names()),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $into = trim((string) $context->setting('into', ''));
        $expected = $this->expectedFields($context);

        if ($into === '' || $expected === []) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say which values to expect or what to remember them as.',
            ]);
        }

        $input = $this->input($context);

        if ($input === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'There was nothing to read values out of.',
            ]);
        }

        $gateway = $this->gateways->guarded(
            $context->conversation->bot,
            (string) $context->setting('provider', ''),
        );

        if (! $gateway->isAvailable()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                'reason' => 'No model is available for this installation.',
            ]);
        }

        $reply = $gateway->ask(new AiPrompt(
            instruction: trim($context->filled('instruction')),
            question: $input,
            schema: array_fill_keys($expected, 'the value, or null when the text does not say'),
        ));

        if (! $reply->ok()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                'failure' => $reply->failure()?->value,
                'reason' => $reply->error(),
                'usage' => $reply->usage()->toArray(),
            ]);
        }

        $values = $this->valuesOf($reply->data(), $expected);

        if ($values === []) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'provider' => $gateway->name(),
                'reason' => 'The model returned none of the expected values.',
                'usage' => $reply->usage()->toArray(),
            ]);
        }

        $this->rememberRecord($context->runtime, $into, $this->record($expected, $values));

        return StepResult::next()->traced(RunStep::PASSED, [
            'provider' => $gateway->name(),
            'into' => $into,
            'fields' => array_keys($values),
            'usage' => $reply->usage()->toArray(),
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('instruction', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => 'This node does not say what to pull out of the message.'];
        }

        if (trim((string) $step->setting('into', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This node does not say what to remember the values as, so nothing downstream could use them.',
            ];
        }

        if ($this->namesIn($step) === []) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This node does not list the values to expect, and a model asked for nothing in particular returns nothing usable.',
            ];
        }

        return $issues;
    }

    /**
     * Every expected name gets a variable, including the ones the model did not
     * find.
     *
     * A name left unwritten keeps whatever an earlier run of the same node put
     * there — a previous traveller's city, printed into this traveller's
     * confirmation — and that is far harder to notice than a gap in a sentence.
     *
     * @param  list<string>  $expected
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function record(array $expected, array $values): array
    {
        $record = [];

        foreach ($expected as $name) {
            $record[$name] = $values[$name] ?? '';
        }

        return $record;
    }

    /**
     * The values the model actually returned, as text.
     *
     * Anything that is not a plain value is dropped rather than flattened: a
     * nested object arriving where a city name was asked for means the model
     * answered a different question, and json-encoding it into a variable would
     * print a fragment of JSON in the middle of a sentence to a customer.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $expected
     * @return array<string, string>
     */
    private function valuesOf(array $data, array $expected): array
    {
        $values = [];

        foreach ($expected as $name) {
            $value = $data[$name] ?? null;

            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $text = $this->textOf($value);

            if (trim($text) !== '') {
                $values[$name] = $text;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function expectedFields(StepContext $context): array
    {
        return $this->names((array) $context->setting('fields', []));
    }

    /**
     * @return list<string>
     */
    private function namesIn(Step $step): array
    {
        return $this->names((array) $step->setting('fields', []));
    }

    /**
     * @param  array<int|string, mixed>  $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => trim((string) (is_scalar($name) ? $name : '')), $names),
            static fn (string $name): bool => $name !== '',
        )));
    }

    /**
     * What the model reads: the configured text, or the message that arrived.
     *
     * The fallback is what makes the node usable straight after a question —
     * the common case is "read the values out of what they just said", and an
     * author forced to type a variable name for that would be typing the same
     * one every time.
     */
    private function input(StepContext $context): string
    {
        $input = trim($context->filled('input'));

        return $input === '' ? trim((string) ($context->said ?? '')) : $input;
    }
}
