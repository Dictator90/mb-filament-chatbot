<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use Carbon\CarbonImmutable;
use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;
use Throwable;

/**
 * Today's date, for campaigns and cut-offs.
 *
 * Both sides are normalised to Y-m-d before comparing, because that is the one
 * format where a string comparison and a date comparison agree. Left as typed,
 * "01.03.2026" sorts before "01.12.2025" and a branch meant to close after a
 * deadline would open again.
 *
 * An unparseable value answers false rather than falling back to today. Today is
 * a real date that half the operators would match, so guessing here would fire
 * branches nobody drew.
 */
final class DateCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.date.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Date;
    }

    public function hint(): ?string
    {
        return __('chatbot::nodes.conditions.date.hint');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $expected = $this->date($value);

        if ($expected === null && ConditionKind::operatorNeedsValue($operator)) {
            return false;
        }

        return $this->compare($operator, now()->toDateString(), $expected);
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim((string) $value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
