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
 * Walks a list one entry at a time, and always stops.
 *
 * The node a scenario needs the moment a lookup returns more than one record:
 * say a line about each booking, run an action per invoice, ask about each
 * passenger. The "each" exit is entered once per entry, the branch drawn from
 * it comes back to this node, and the "done" exit is taken when the list — or
 * the limit — runs out.
 *
 * **The limit is a required setting, not a default with an escape hatch.** A
 * loop with no finite bound is not a slow scenario, it is a conversation that
 * never reaches its next sentence: the walk spins inside one turn until the
 * engine's own step ceiling stops it, and the visitor is left looking at a chat
 * that answered nothing while the server did the work of a hundred. Worse, the
 * author will not see it. On the three records their test data holds, an
 * unbounded loop and a bounded one are the same picture; the customer with two
 * hundred is the one who finds out. So a missing or unreasonable limit is a
 * blocking defect while the scenario is being edited, and a refusal — out of
 * the "done" exit, with the reason in the trace — if one ever reaches a
 * conversation anyway.
 *
 * The list is read as lines, because that is how the lookup node stores one:
 * a `query` in list mode writes its rows into a variable separated by
 * newlines. Anything else the author puts in that variable is looped over the
 * same way, which keeps the two nodes usable together without this one having
 * to know about data resources at all.
 */
final class ForEachStep extends StepType
{
    /** The most entries any single loop may run through. */
    private const CEILING = 100;

    public static function key(): string
    {
        return 'for_each';
    }

    public function label(): string
    {
        return 'For each entry';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-path';
    }

    public function color(): string
    {
        return '#c2410c';
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
        return ['each', 'done'];
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'action', 'condition'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::variable('source')
                ->label('Go through')
                ->required()
                ->hint('A variable holding a list — one entry per line, as a lookup in list mode writes it.'),

            Field::text('item')
                ->label('Remember each entry as')
                ->placeholder('booking_line')
                ->required(),

            Field::text('index')
                ->label('Remember its number as')
                ->placeholder('booking_number'),

            Field::number('max_items')
                ->label('Never more than')
                ->default(10)
                ->min(1)
                ->max(self::CEILING)
                ->required()
                ->hint('Entries beyond this many are not walked. A loop without a bound is a conversation that never finishes.'),
        ];
    }

    /**
     * Hands out the next entry, or leaves through "done".
     *
     * The position is kept on the conversation and cleared as soon as the loop
     * leaves, for the same reason the picker drops its offer: a position left
     * behind outlives the list it counted, and the next visit would start the
     * loop halfway through a list that is not the same list any more.
     */
    public function run(StepContext $context): StepResult
    {
        $source = trim((string) $context->setting('source', ''));
        $item = trim((string) $context->setting('item', ''));

        if ($source === '' || $item === '') {
            $this->forget($context);

            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say what to go through or what to remember each entry as.',
            ]);
        }

        $limit = $this->limit($context);

        if ($limit === null) {
            $this->forget($context);

            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node has no finite limit, and a loop without one never ends.',
            ]);
        }

        $entries = $this->entries($context, $source);
        $position = $this->position($context);
        $bound = min(count($entries), $limit);

        if ($position >= $bound) {
            $this->forget($context);

            return StepResult::second()->traced(RunStep::PASSED, [
                'source' => $source,
                'walked' => $position,
                'entries' => count($entries),
                'reason' => count($entries) > $limit ? 'the limit was reached' : 'the list is finished',
            ]);
        }

        $context->runtime->store($item, $entries[$position]);

        $index = trim((string) $context->setting('index', ''));

        if ($index !== '') {
            $context->runtime->store($index, (string) ($position + 1));
        }

        $this->remember($context, $position + 1);

        return StepResult::next()->traced(RunStep::PASSED, [
            'source' => $source,
            'position' => $position + 1,
            'of' => $bound,
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('source', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => 'This node does not say which list to go through.'];
        }

        if (trim((string) $step->setting('item', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This node does not say what to remember each entry as, so the branch below it would read nothing.',
            ];
        }

        $raw = $step->setting('max_items');
        $limit = is_numeric($raw) ? (int) $raw : 0;

        if ($limit < 1) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This loop has no limit. Without one the conversation walks the whole list and may never reach its next answer.',
            ];
        } elseif ($limit > self::CEILING) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'A loop of more than '.self::CEILING.' entries holds the conversation open long past the point a visitor waits.',
            ];
        }

        return $issues;
    }

    /**
     * The bound this loop runs under, or null when it has none it may use.
     *
     * A limit above the ceiling is refused rather than clamped down to it. A
     * clamp would let a scenario be published with 5000 written on the node and
     * walk 100 entries instead, and the author would go on reading the number
     * they typed.
     */
    private function limit(StepContext $context): ?int
    {
        $raw = $context->setting('max_items');

        if (! is_numeric($raw)) {
            return null;
        }

        $limit = (int) $raw;

        return $limit >= 1 && $limit <= self::CEILING ? $limit : null;
    }

    /**
     * @return list<string>
     */
    private function entries(StepContext $context, string $source): array
    {
        $value = (string) ($context->runtime->value($source) ?? '');

        if (trim($value) === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $value);

        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function position(StepContext $context): int
    {
        return max(0, (int) (((array) $context->conversation->meta)[$this->stateKey($context)] ?? 0));
    }

    private function remember(StepContext $context, int $position): void
    {
        $meta = (array) $context->conversation->meta;
        $meta[$this->stateKey($context)] = $position;

        $context->conversation->forceFill(['meta' => $meta])->save();
    }

    private function forget(StepContext $context): void
    {
        $meta = (array) $context->conversation->meta;

        if (! array_key_exists($this->stateKey($context), $meta)) {
            return;
        }

        unset($meta[$this->stateKey($context)]);

        $context->conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }

    /**
     * The key is per node, so two loops in one scenario cannot count for each
     * other — including the common case of a loop nested inside another.
     */
    private function stateKey(StepContext $context): string
    {
        return 'for_each:'.$context->step->key;
    }
}
