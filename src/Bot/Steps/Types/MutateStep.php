<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Contracts\HasSideEffect;
use MB\FilamentChatbot\Data\DataWriter;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Writes one row of a declared data resource.
 *
 * The only node in the package that changes the host's records without a line
 * of host code, and the rules around it are correspondingly narrow: the resource
 * must be declared writable, only its declared write fields are accepted, an
 * update needs a lock column, and the release refuses to publish a scenario
 * where this node is reachable without an approval above it.
 *
 * That last rule is why the node exists in this shape at all. A bot that can
 * write is a bot that can be talked into writing, and the defence is not a
 * cleverer runtime check — it is a visible node on the canvas that a person had
 * to draw, and a publication that fails while it is missing.
 */
final class MutateStep extends StepType implements HasSideEffect
{
    public function __construct(private readonly DataWriter $writer) {}

    public static function key(): string
    {
        return 'mutate';
    }

    public function label(): string
    {
        return 'Write a record';
    }

    public function icon(): string
    {
        return 'heroicon-o-pencil-square';
    }

    public function color(): string
    {
        return '#dc2626';
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
        return ['written', 'could not'];
    }

    public function sideEffect(Step $step): SideEffect
    {
        return SideEffect::Write;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'close', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::dataResource('resource')
                ->label('Which resource')
                ->required(),
            Field::select('operation')
                ->label('What to do')
                ->required()
                ->default('insert')
                ->options([
                    'insert' => 'Add a record',
                    'update' => 'Change a record',
                ]),
            Field::variable('key')
                ->label('Which record')
                ->hint('The variable holding the record to change. Usually filled by a lookup above.')
                ->visibleWhen('operation', 'update'),
            Field::variable('expect')
                ->label('As it was when read')
                ->hint('The variable holding the lock column read by the lookup above. Without it the update is refused.')
                ->visibleWhen('operation', 'update'),
            Field::keyValue('values')
                ->label('Fields to write')
                ->hint('Values support {{variables}}. Only fields the resource declares writable are accepted.'),
            Field::text('into')
                ->label('Remember the record as')
                ->hint('Receives the key of the row that was written.'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $resource = trim((string) $context->setting('resource', ''));
        $operation = (string) $context->setting('operation', 'insert');
        $values = $this->filled($context);

        if ($resource === '') {
            return StepResult::second()->traced(RunStep::FAILED, ['reason' => 'no resource chosen']);
        }

        $runtime = RuntimeContext::forConversation($context->conversation);

        $result = $operation === 'update'
            ? $this->writer->update(
                $resource,
                $context->runtime->value((string) $context->setting('key', '')),
                $values,
                $runtime,
                $context->runtime->value((string) $context->setting('expect', '')),
            )
            : $this->writer->insert($resource, $values, $runtime);

        if (! $result->ok()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'resource' => $resource,
                'reason' => $result->error(),
            ]);
        }

        $written = $result->first() ?? [];
        $into = trim((string) $context->setting('into', ''));

        if ($into !== '') {
            $context->runtime->store($into, (string) (reset($written) ?: ''));
        }

        return StepResult::next()->traced(RunStep::PASSED, [
            'resource' => $resource,
            'operation' => $operation,
            'record' => reset($written) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
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
            $issues[] = ['severity' => 'blocking', 'message' => 'This node does not say what it writes to.'];
        }

        if ((array) $step->setting('values', []) === []) {
            $issues[] = ['severity' => 'blocking', 'message' => 'This node writes nothing.'];
        }

        if ((string) $step->setting('operation', 'insert') === 'update') {
            if (trim((string) $step->setting('key', '')) === '') {
                $issues[] = ['severity' => 'blocking', 'message' => 'This node changes a record without saying which one.'];
            }

            /*
             * Caught here rather than left to fail at runtime, because a
             * scenario that reaches this node without having read the record
             * first is not one bad turn — it is a branch that can never work,
             * and the author is the only person who can see why.
             */
            if (trim((string) $step->setting('expect', '')) === '') {
                $issues[] = [
                    'severity' => 'blocking',
                    'message' => 'This node changes a record without saying how it looked when it was read.',
                ];
            }
        }

        return $issues;
    }

    /**
     * Values with {{variables}} substituted.
     *
     * Substituted here rather than left to the writer, so that what reaches the
     * database is a plain value and the writer has one fewer thing it is allowed
     * to interpret.
     *
     * @return array<string, mixed>
     */
    private function filled(StepContext $context): array
    {
        $values = [];

        foreach ((array) $context->setting('values', []) as $field => $value) {
            $values[(string) $field] = is_string($value)
                ? $context->runtime->fill($value)
                : $value;
        }

        return $values;
    }
}
