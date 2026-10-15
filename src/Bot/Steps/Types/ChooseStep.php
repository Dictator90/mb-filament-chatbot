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
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Shows the visitor their own records and remembers the one they pick.
 *
 * This is the node every installation would otherwise write for itself. "Which
 * of your bookings?", "which order?", "which ticket?" are the same question
 * about a different table, and each hand-written version repeats the part that
 * is easy to get wrong — reading only what was declared, listing it, and
 * matching an answer typed on a channel that draws no buttons.
 *
 * **Who is asking comes from the conversation, never from the settings.** The
 * runtime context is built from the conversation row alone, so the access scope
 * of the resource decides which records exist for this visitor. Nothing here
 * may take a scope value from the answers bag, from the node's settings or from
 * the message: those are the visitor's own words, and a visitor who can name
 * their own customer id can name somebody else's — the read that follows is
 * valid, indexed and impossible to tell from a legitimate one afterwards. The
 * list this node offers is exactly the list the declaration allows, which is
 * also why the pick is a pick out of that list and not a free-text lookup.
 */
final class ChooseStep extends StepType
{
    use WritesRecordVariables;

    public function __construct(private readonly DataReader $reader) {}

    public static function key(): string
    {
        return 'choose';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.choose.label');
    }

    /**
     * The wording used when the answer names none of the records shown.
     *
     * A method rather than a constant now that it is translated: a constant is
     * resolved at class-load time and would pin whichever locale the first
     * request of a worker used onto every conversation it served afterwards.
     */
    private function noneDefault(): string
    {
        return __('chatbot::nodes.steps.choose.none_option');
    }

    public function icon(): string
    {
        return 'heroicon-o-list-bullet';
    }

    public function color(): string
    {
        return '#0891b2';
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
        return array_values((array) __('chatbot::nodes.steps.choose.exits'));
    }

    public function speaks(): bool
    {
        return true;
    }

    public function waitsForReply(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'approval', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::dataResource('resource')
                ->label(__('chatbot::nodes.steps.choose.fields.resource.label'))
                ->required(),

            Field::keyValue('filters')
                ->label(__('chatbot::nodes.steps.choose.fields.filters.label'))
                ->hint(__('chatbot::nodes.steps.choose.fields.filters.hint')),

            Field::number('limit')
                ->label(__('chatbot::nodes.steps.choose.fields.limit.label'))
                ->default(5)
                ->min(1)
                ->max(10),

            Field::text('label')
                ->label(__('chatbot::nodes.steps.choose.fields.label.label'))
                ->placeholder(__('chatbot::nodes.steps.choose.fields.label.placeholder'))
                ->required()
                ->hint(__('chatbot::nodes.steps.choose.fields.label.hint')),

            Field::text('into')
                ->label(__('chatbot::nodes.steps.choose.fields.into.label'))
                ->placeholder(__('chatbot::nodes.steps.choose.fields.into.placeholder'))
                ->required()
                ->hint(__('chatbot::nodes.steps.choose.fields.into.hint')),

            Field::text('none_label')
                ->label(__('chatbot::nodes.steps.choose.fields.none_label.label'))
                ->default($this->noneDefault()),
        ];
    }

    /**
     * Reads the records and offers them, or leaves at once.
     *
     * **A refusal and an empty list take the same exit, and that is the right
     * shape rather than a lost distinction.** Asking "which of your bookings?"
     * of somebody who has nothing to choose from is not a question, it is a
     * dead end with a prompt on it — and that is equally true whether they have
     * no bookings or the resource could not be read. Both cases need the same
     * next node: say something honest and fetch a person. Two arrows out of
     * this node landing on the same one would draw a choice the author does not
     * have. What the two cases do need is to be told apart afterwards, by
     * whoever fixes things, so the refusal carries its reason into the trace.
     */
    public function run(StepContext $context): StepResult
    {
        $resource = trim((string) $context->setting('resource', ''));
        $into = trim((string) $context->setting('into', ''));

        if ($resource === '' || $into === '') {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'reason' => 'The node does not say what to offer or what to remember the pick as.',
            ]);
        }

        $result = $this->reader->read(
            new DataQuery(
                resource: $resource,
                mode: DataQueryMode::List,
                filters: $this->filters($context),
                limit: $this->limit($context),
            ),
            RuntimeContext::forConversation($context->conversation),
        );

        if (! $result->ok()) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'resource' => $resource,
                'reason' => $result->error(),
            ]);
        }

        if ($result->isEmpty()) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, ['resource' => $resource]);
        }

        $offered = [];

        foreach ($result->rows() as $row) {
            $offered[] = ['label' => $this->describe($context, $row), 'row' => $row];
        }

        $this->rememberOffer($context, $offered);

        /*
         * The labels travel beside the message as well as inside it. The text
         * carries the numbered list a channel without buttons needs; the labels
         * are what a channel with buttons draws. Sending only the text left the
         * widget listing records the visitor had no way to tap.
         */
        $context->runtime->say(
            $this->question($context, $offered),
            array_column($offered, 'label'),
        );

        return StepResult::wait()
            ->traced(RunStep::WAITING, ['resource' => $resource, 'offered' => count($offered)])
            ->spoken();
    }

    /**
     * Takes the record the answer names, out of the ones that were shown.
     *
     * The list is read back from the conversation rather than queried again,
     * and that is not an optimisation. Between the question and the answer the
     * records can change — one is cancelled, another is created, a status moves
     * and the sort order with it — so a second read can return a different list
     * in a different order. The visitor who typed "2" chose the second line
     * they were shown, and a node that re-reads would hand the scenario the
     * second line of a list nobody ever saw.
     */
    public function resume(StepContext $context, string $answer): StepResult
    {
        $offered = $this->offered($context);
        $this->forgetOffer($context);

        if ($offered === []) {
            return StepResult::second()->traced(RunStep::BRANCHED, [
                'reason' => 'The records offered are no longer known.',
            ]);
        }

        $picked = $this->match($offered, $answer);

        if ($picked === null) {
            return StepResult::second()->traced(RunStep::BRANCHED, ['answer' => $answer]);
        }

        $into = trim((string) $context->setting('into', ''));

        $this->rememberRecord($context->runtime, $into, $picked['row']);

        return StepResult::next()->traced(RunStep::ANSWERED, [
            'into' => $into,
            'picked' => $picked['label'],
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
            $issues[] = ['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.choose.issues.no_resource')];
        }

        if (($missing = $this->resourceIssue($step)) !== null) {
            $issues[] = $missing;
        }

        if (trim((string) $step->setting('into', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.choose.issues.no_into'),
            ];
        }

        return $issues;
    }

    /**
     * The one line that stands for a record.
     *
     * Placeholders are filled from the record and from nothing else. Falling
     * back to the conversation's variables for an unknown name looks harmless
     * and is not: a mistyped field name would then quietly print the visitor's
     * own answer next to every record in the list, and each line would read as
     * a fact about that record.
     *
     * @param  array<string, scalar|null>  $row
     */
    private function describe(StepContext $context, array $row): string
    {
        $template = trim((string) $context->setting('label', ''));

        if ($template === '') {
            return implode(' — ', array_map(
                fn (mixed $value): string => $this->textOf($value),
                array_values($row),
            ));
        }

        return trim((string) preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/u',
            fn (array $matches): string => $this->textOf($row[$matches[1]] ?? null),
            $template,
        ));
    }

    /**
     * @param  list<array{label: string, row: array<string, scalar|null>}>  $offered
     */
    private function question(StepContext $context, array $offered): string
    {
        $lines = [];

        foreach ($offered as $position => $entry) {
            $lines[] = ($position + 1).'. '.$entry['label'];
        }

        $lines[] = (count($offered) + 1).'. '.$this->noneLabel($context);

        return implode(PHP_EOL, $lines);
    }

    /**
     * Finds the record an answer names, or null for "none of these".
     *
     * Numbers first, then whole labels, then a label an answer begins. The
     * order is what keeps a short answer from swallowing a longer one: matched
     * by prefix first, an answer of "10" would take the first line rather than
     * the tenth, and on a channel that draws no buttons a number is the usual
     * way to answer.
     *
     * @param  list<array{label: string, row: array<string, scalar|null>}>  $offered
     * @return array{label: string, row: array<string, scalar|null>}|null
     */
    private function match(array $offered, string $answer): ?array
    {
        $answer = $this->normalise($answer);

        if ($answer === '') {
            return null;
        }

        if (ctype_digit($answer)) {
            return $offered[((int) $answer) - 1] ?? null;
        }

        foreach ($offered as $entry) {
            if ($this->normalise($entry['label']) === $answer) {
                return $entry;
            }
        }

        foreach ($offered as $entry) {
            $label = $this->normalise($entry['label']);

            if ($label !== '' && str_starts_with($label, $answer)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Keeps the offered records with the conversation, not with the answers.
     *
     * The answers bag holds what the visitor said; this is what the bot showed,
     * and the two are read by different code for different reasons. Stored
     * among the answers it would appear in the variable picker, be offered for
     * substitution into messages, and — the one that matters — be one setting
     * away from reaching a query as though the visitor had supplied it.
     *
     * @param  list<array{label: string, row: array<string, scalar|null>}>  $offered
     */
    private function rememberOffer(StepContext $context, array $offered): void
    {
        $meta = (array) $context->conversation->meta;
        $meta[$this->offerKey($context)] = $offered;

        $this->saveMeta($context->conversation, $meta);
    }

    /**
     * @return list<array{label: string, row: array<string, scalar|null>}>
     */
    private function offered(StepContext $context): array
    {
        $stored = ((array) $context->conversation->meta)[$this->offerKey($context)] ?? [];
        $offered = [];

        foreach ((array) $stored as $entry) {
            if (! is_array($entry) || ! is_array($entry['row'] ?? null)) {
                continue;
            }

            $offered[] = ['label' => (string) ($entry['label'] ?? ''), 'row' => $entry['row']];
        }

        return $offered;
    }

    /**
     * Drops the offer as soon as it has been answered.
     *
     * A list left behind outlives the question it belonged to, and the node is
     * entered again on the next visit: an answer of "1" meant for whatever is
     * being asked then would silently pick a record from a conversation that
     * happened weeks ago.
     */
    private function forgetOffer(StepContext $context): void
    {
        $meta = (array) $context->conversation->meta;

        unset($meta[$this->offerKey($context)]);

        $this->saveMeta($context->conversation, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function saveMeta(Conversation $conversation, array $meta): void
    {
        $conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }

    /**
     * The key is per node, so that two pickers in one scenario cannot answer
     * for each other.
     */
    private function offerKey(StepContext $context): string
    {
        return 'choose:'.$context->step->key;
    }

    private function noneLabel(StepContext $context): string
    {
        $label = trim((string) $context->setting('none_label', $this->noneDefault()));

        return $label === '' ? $this->noneDefault() : $label;
    }

    /**
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

    private function limit(StepContext $context): int
    {
        $limit = (int) $context->setting('limit', 5);

        return max(1, min(10, $limit));
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
