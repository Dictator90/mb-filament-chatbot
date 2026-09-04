<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions;

/**
 * The shape of a condition, which decides what the editor draws next to it.
 *
 * Kinds rather than a free list of operators per field: an author choosing
 * "is the visitor known" should not be offered "greater than", and a field
 * author should not have to remember to exclude it.
 */
enum ConditionKind: string
{
    /** Yes or no. No value box at all. */
    case Flag = 'flag';

    case Text = 'text';

    case Number = 'number';

    /** A time of day — working hours, quiet hours. */
    case Clock = 'clock';

    case Date = 'date';

    /** One of a fixed list. */
    case Choice = 'choice';

    /**
     * The operators this kind allows.
     *
     * "Filled" and "blank" are on every kind except the flag, and that is the
     * one people reach for most: a scenario nearly always needs to know whether
     * something was collected at all before it can compare it. Leave them off
     * and authors write `equals ""`, which is not the same question — a slot
     * that was never asked and a slot answered with a space are different
     * situations, and only one of them is worth asking again.
     *
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Flag => ['yes', 'no'],
            self::Text => ['equals', 'not_equals', 'contains', 'starts_with', 'one_of', 'filled', 'blank'],
            self::Number => ['equals', 'not_equals', 'at_least', 'at_most', 'filled', 'blank'],
            self::Clock, self::Date => ['at_least', 'at_most', 'equals', 'filled', 'blank'],
            self::Choice => ['equals', 'not_equals', 'one_of', 'filled', 'blank'],
        };
    }

    /**
     * Whether an operator still needs a value from the author.
     *
     * The editor hides the box for the rest. A visible box that is ignored is
     * how an author ends up believing they set a threshold that nothing reads.
     */
    public static function operatorNeedsValue(string $operator): bool
    {
        return ! in_array($operator, ['yes', 'no', 'filled', 'blank'], true);
    }
}
