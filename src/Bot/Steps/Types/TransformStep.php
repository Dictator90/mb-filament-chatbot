<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Throwable;

/**
 * Reshapes a value the conversation already has into another variable.
 *
 * The small work every scenario needs and no node was doing: a name typed in
 * capitals that has to be greeted with, a phone number with spaces in it that
 * has to be matched against a record, a date to print the way people read
 * dates, an empty answer that needs a stand-in before it reaches a message.
 * Without this node each of those is either a host action written for one
 * sentence, or a bot that says "HELLO IVAN".
 *
 * **The operations are a closed list, and it stays closed.** The obvious
 * alternative is one field holding an expression, and it is the single most
 * expensive thing that could be added to this package. An expression field is
 * code execution, the code is typed into a web form by whoever edits scenarios
 * — a marketer, a support lead, somebody with the panel password — and it runs
 * on the application server with the application's database behind it. Every
 * sandbox for that is a project of its own that is bypassed by a construct
 * nobody thought of, and the reward for all of it is a shorter way to write
 * "uppercase". A list of named operations is refused by not existing: an
 * operation this class does not implement cannot be reached, whatever anybody
 * types.
 *
 * One exit. There is nothing here that can fail in a way a scenario should
 * branch on — a value that was not a date is still a value — so a failure is
 * carried in the trace and the walk goes on.
 */
final class TransformStep extends StepType
{
    private const OPERATIONS = [
        'upper' => 'Upper case',
        'lower' => 'Lower case',
        'trim' => 'Trim spaces',
        'first_word' => 'First word',
        'digits_only' => 'Digits only',
        'truncate' => 'Shorten to a length',
        'default' => 'Use a stand-in when empty',
        'join' => 'Join several variables',
        'format_date' => 'Format a date',
        'replace' => 'Replace text',
    ];

    public static function key(): string
    {
        return 'transform';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.transform.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-adjustments-horizontal';
    }

    public function color(): string
    {
        return '#0d9488';
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
        return ['say', 'condition', 'query', 'action'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::variable('source')
                ->label('Take')
                ->required(),

            Field::select('operation')
                ->label('And')
                ->default('trim')
                ->options(self::OPERATIONS),

            Field::number('length')
                ->label('Keep this many characters')
                ->default(80)
                ->min(1)
                ->max(4000)
                ->visibleWhen('operation', 'truncate'),

            Field::text('fallback')
                ->label('Stand-in')
                ->hint('Used when the value is empty. May contain {{variables}}.')
                ->visibleWhen('operation', 'default'),

            Field::list('with')
                ->label('Together with')
                ->hint('More variable names, joined after the first one.')
                ->visibleWhen('operation', 'join'),

            Field::text('separator')
                ->label('Separated by')
                ->default(', ')
                ->visibleWhen('operation', 'join'),

            Field::text('format')
                ->label('Date format')
                ->default('d.m.Y')
                ->hint("PHP's date format — d.m.Y, j F Y, H:i.")
                ->visibleWhen('operation', 'format_date'),

            Field::text('search')
                ->label('Find')
                ->visibleWhen('operation', 'replace'),

            Field::text('replace')
                ->label('Replace with')
                ->visibleWhen('operation', 'replace'),

            Field::text('into')
                ->label('Remember the result as')
                ->placeholder('greeting_name')
                ->required(),
        ];
    }

    /**
     * Runs the operation, or writes nothing at all.
     *
     * **An operation this node does not know leaves the target variable
     * untouched.** The tempting alternative — copy the source across unchanged —
     * is what turns a typo in a saved scenario into a message that reads
     * plausibly and says the wrong thing: "we will call you on IVAN" from a
     * scenario whose author believed they were formatting a phone number.
     * Nothing written means the message shows a gap, which is the one outcome an
     * author notices in the first conversation.
     */
    public function run(StepContext $context): StepResult
    {
        $into = trim((string) $context->setting('into', ''));
        $source = trim((string) $context->setting('source', ''));
        $operation = trim((string) $context->setting('operation', ''));

        if ($into === '' || $source === '') {
            return StepResult::next()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say what to take or what to remember the result as.',
            ]);
        }

        if (! array_key_exists($operation, self::OPERATIONS)) {
            return StepResult::next()->traced(RunStep::FAILED, [
                'into' => $into,
                'operation' => $operation,
                'reason' => 'No such operation, so nothing was written.',
            ]);
        }

        $value = (string) ($context->runtime->value($source) ?? '');
        $result = $this->apply($context, $operation, $value);

        $context->runtime->store($into, $result);

        return StepResult::next()->traced(RunStep::PASSED, [
            'into' => $into,
            'operation' => $operation,
            'value' => $result,
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('source', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => 'This node does not say which value to reshape.'];
        }

        if (trim((string) $step->setting('into', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => 'This node does not say what to remember the result as, so it would do nothing at all.',
            ];
        }

        $operation = trim((string) $step->setting('operation', ''));

        if ($operation !== '' && ! array_key_exists($operation, self::OPERATIONS)) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => "[{$operation}] is not an operation this node can perform, so it would write nothing.",
            ];
        }

        return $issues;
    }

    private function apply(StepContext $context, string $operation, string $value): string
    {
        return match ($operation) {
            'upper' => mb_strtoupper($value),
            'lower' => mb_strtolower($value),
            'trim' => trim($value),
            'first_word' => $this->firstWord($value),
            'digits_only' => (string) preg_replace('/\D+/u', '', $value),
            'truncate' => $this->truncate($context, $value),
            'default' => trim($value) === '' ? $context->filled('fallback') : $value,
            'join' => $this->join($context, $value),
            'format_date' => $this->formatDate($context, $value),
            'replace' => $this->replace($context, $value),
            default => $value,
        };
    }

    private function firstWord(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value), 2);

        return $words === false ? '' : (string) ($words[0] ?? '');
    }

    private function truncate(StepContext $context, string $value): string
    {
        $length = (int) $context->setting('length', 80);

        return mb_substr($value, 0, max(1, $length));
    }

    /**
     * Joins the source with the other named variables.
     *
     * Empty values are dropped before joining rather than joined and trimmed
     * afterwards. Kept, a missing middle name turns "Ivan, , Petrov" into a
     * sentence that reads as a bug to the customer, and the trailing separator
     * of a missing last value survives every trim() someone adds later.
     */
    private function join(StepContext $context, string $value): string
    {
        $separator = (string) $context->setting('separator', ', ');
        $parts = trim($value) === '' ? [] : [$value];

        foreach ((array) $context->setting('with', []) as $name) {
            if (is_array($name)) {
                continue;
            }

            $name = trim((string) $name);
            $other = $name === '' ? '' : (string) ($context->runtime->value($name) ?? '');

            if (trim($other) !== '') {
                $parts[] = $other;
            }
        }

        return implode($separator, $parts);
    }

    /**
     * Formats a date, and leaves a value that is not one alone.
     *
     * Carbon reads far more than a date — "now", "+3 days", an empty string as
     * the current moment — so an unparsable value is not the only wrong answer
     * available here. A phone number turned into today's date would print as a
     * perfectly formatted sentence about the wrong day, so anything Carbon
     * refuses, and anything that arrived empty, is passed through untouched and
     * said so in the trace.
     */
    private function formatDate(StepContext $context, string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $format = trim((string) $context->setting('format', 'd.m.Y'));

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return $value;
        }

        return $date instanceof CarbonInterface
            ? $date->format($format === '' ? 'd.m.Y' : $format)
            : $value;
    }

    private function replace(StepContext $context, string $value): string
    {
        $search = (string) $context->setting('search', '');

        if ($search === '') {
            return $value;
        }

        return str_replace($search, $context->filled('replace'), $value);
    }
}
