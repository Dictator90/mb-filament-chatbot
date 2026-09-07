<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\Concerns\WritesRecordVariables;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Data\DataQuery;
use MB\FilamentChatbot\Data\DataReader;
use MB\FilamentChatbot\Data\DataResult;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Reads the host's own records into the conversation's variables.
 *
 * The node that lets a scenario answer with facts instead of with a promise
 * that somebody will look it up. Everything it may read was declared as a data
 * resource first, and this node adds nothing to that declaration: it chooses a
 * resource, a shape and a few equality filters, and the reader refuses whatever
 * the declaration did not allow.
 *
 * **Who is asking comes from the conversation, never from the settings.** The
 * runtime context is built from the conversation row alone, so an access scope
 * resolves against `conversation.contact_id` and the like — server-side facts.
 * It must stay that way. Every other value in reach at this point was written
 * by the visitor or shaped by their message: the answers bag is their own
 * replies stored under friendly names, and a step setting is one persuaded
 * author away from pointing at it. A visitor who can name their own customer id
 * can name somebody else's, and the read that results is valid, fast, indexed
 * and completely indistinguishable in the logs from a legitimate one. Filters
 * may carry visitor-supplied values because they only narrow within the scope;
 * the scope itself may not.
 *
 * Two outputs. The first means rows came back, the second means they did not —
 * and "could not read" is told apart from "nothing matched" in the trace rather
 * than by a third arrow, because both branches lead the scenario to the same
 * place while only one of them is a fault somebody has to fix.
 */
final class QueryStep extends StepType
{
    use WritesRecordVariables;

    public function __construct(private readonly DataReader $reader) {}

    public static function key(): string
    {
        return 'query';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.query.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-magnifying-glass-circle';
    }

    public function color(): string
    {
        return '#0284c7';
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
        return array_values((array) __('chatbot::nodes.steps.query.exits'));
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'choose', 'handoff'];
    }

    /**
     * The node's settings.
     *
     * **Filters compare for equality and nothing else, on purpose.** The wider
     * set — ranges, substrings, "one of" — needs a filter builder in the editor
     * that does not exist yet, and each of those comparisons is refused per
     * field by the declaration anyway, so an author would be choosing from a
     * list whose entries mostly fail. A key-value box describes exactly what
     * genuinely works. The alternative considered and rejected was a third
     * column holding an operator: a control that is drawn, filled in, saved and
     * then ignored teaches the author a rule the bot does not follow, and the
     * scenario that relies on it is wrong in a way no screen shows.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::dataResource('resource')
                ->label(__('chatbot::nodes.steps.query.fields.resource.label'))
                ->required(),

            Field::select('mode')
                ->label(__('chatbot::nodes.steps.query.fields.mode.label'))
                ->default(DataQueryMode::First->value)
                ->options(fn (): array => collect(DataQueryMode::cases())
                    ->mapWithKeys(fn (DataQueryMode $mode): array => [$mode->value => $mode->getLabel()])
                    ->all()),

            Field::keyValue('filters')
                ->label(__('chatbot::nodes.steps.query.fields.filters.label'))
                ->hint(__('chatbot::nodes.steps.query.fields.filters.hint')),

            Field::select('select')
                ->label(__('chatbot::nodes.steps.query.fields.select.label'))
                ->multiple()
                ->options([])
                ->hint(__('chatbot::nodes.steps.query.fields.select.hint')),

            Field::number('limit')
                ->label(__('chatbot::nodes.steps.query.fields.limit.label'))
                ->min(1)
                ->max(100),

            Field::text('into')
                ->label(__('chatbot::nodes.steps.query.fields.into.label'))
                ->placeholder(__('chatbot::nodes.steps.query.fields.into.placeholder'))
                ->required()
                ->hint(__('chatbot::nodes.steps.query.fields.into.hint')),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $resource = trim((string) $context->setting('resource', ''));
        $into = trim((string) $context->setting('into', ''));

        if ($resource === '' || $into === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say what to read or what to remember it as.',
            ]);
        }

        $mode = DataQueryMode::tryFrom((string) $context->setting('mode', DataQueryMode::First->value))
            ?? DataQueryMode::First;

        $result = $this->reader->read(
            new DataQuery(
                resource: $resource,
                mode: $mode,
                select: $this->selectedFields($context),
                filters: $this->filters($context),
                limit: $this->limit($context),
            ),
            RuntimeContext::forConversation($context->conversation),
        );

        if (! $result->ok()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'resource' => $resource,
                'reason' => $result->error(),
            ]);
        }

        if ($result->isEmpty()) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, ['resource' => $resource]);
        }

        $this->remember($context, $mode, $into, $result);

        return StepResult::next()->traced(RunStep::QUERIED, [
            'resource' => $resource,
            'mode' => $mode->value,
            'into' => $into,
            'rows' => $result->count(),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        $resource = trim((string) $step->setting('resource', ''));

        return $resource === '' ? [] : ['data_resources' => [$resource]];
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('resource', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.query.issues.no_resource')];
        }

        if (trim((string) $step->setting('into', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.query.issues.no_into'),
            ];
        }

        return $issues;
    }

    /**
     * The result's name, and the count that rides beside a list.
     *
     * The per-field names a single record spreads into — `booking_status`,
     * `booking_date` — are not enumerated here, because they depend on which
     * fields the resource declares returnable and would go stale the moment
     * somebody edits the declaration. The list says what this node names on its
     * own; `docs/data-resources.md` says what a record spreads into.
     *
     * @return list<string>
     */
    public function writes(Step $step): array
    {
        $into = trim((string) $step->setting('into', ''));

        if ($into === '') {
            return [];
        }

        return [$into, $into.'_count'];
    }

    /**
     * Writes the result under names a later message can read back.
     *
     * The three shapes are stored differently because they are read
     * differently, and one uniform answer would be wrong for two of them.
     *
     * A count is a number and goes under the name as it is: "you have
     * {{unpaid}} unpaid invoices" is the whole point of asking for one.
     *
     * A single record is spread over one variable per field
     * ({@see WritesRecordVariables}), because a message inserts one value in the
     * middle of a sentence, and every field glued together with commas reads as
     * one long unintelligible value in the place where a date was meant to go.
     *
     * A list is the opposite case: it is spoken as a list, one record per line,
     * so it is stored the way it will be said. Spreading a list over prefixed
     * names instead would need an index in every name — booking_1_status — and
     * a scenario author cannot write a message against names they cannot know
     * the number of.
     */
    private function remember(StepContext $context, DataQueryMode $mode, string $into, DataResult $result): void
    {
        if ($mode === DataQueryMode::Count) {
            $context->runtime->store($into, (string) $result->count());

            return;
        }

        if ($mode === DataQueryMode::First) {
            $this->rememberRecord($context->runtime, $into, (array) $result->first());

            return;
        }

        $lines = [];

        foreach ($result->rows() as $row) {
            $lines[] = implode(' — ', array_map(
                fn (mixed $value): string => $this->textOf($value),
                array_values($row),
            ));
        }

        $context->runtime->store($into, implode(PHP_EOL, $lines));
        $context->runtime->store($into.'_count', (string) $result->count());
    }

    /**
     * The filters, with variables substituted into their values.
     *
     * Written as a map of field to value, so a field cannot be filtered on
     * twice and the editor can draw it as two columns. The substitution is what
     * makes the node useful at all — the reference number to look up arrived in
     * the conversation a few nodes earlier — and it is safe here in a way it
     * would not be in a scope: a filter can only narrow the rows the scope has
     * already restricted to this visitor.
     *
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private function filters(StepContext $context): array
    {
        $filters = [];

        foreach ((array) $context->setting('filters', []) as $field => $value) {
            $field = trim((string) $field);

            if ($field === '' || is_array($value)) {
                continue;
            }

            $filters[] = [
                'field' => $field,
                'operator' => DataFilterOperator::Equals->value,
                'value' => $context->runtime->fill((string) $value),
            ];
        }

        return $filters;
    }

    /**
     * @return array<int, string>
     */
    private function selectedFields(StepContext $context): array
    {
        $select = $context->setting('select', []);

        return array_values(array_filter(
            array_map(static fn (mixed $field): string => trim((string) $field), (array) $select),
            static fn (string $field): bool => $field !== '',
        ));
    }

    private function limit(StepContext $context): ?int
    {
        $limit = (int) $context->setting('limit', 0);

        return $limit > 0 ? $limit : null;
    }
}
