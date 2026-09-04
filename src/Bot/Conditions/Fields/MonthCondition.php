<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The month, as a number.
 *
 * A number rather than a name for the same reason the weekday is a key: names
 * follow the locale, and a seasonal branch has to keep firing after somebody
 * changes it.
 */
final class MonthCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'month';
    }

    public function label(): string
    {
        return 'Month';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Number;
    }

    public function hint(): ?string
    {
        return '1 to 12';
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, (string) now()->month, $value);
    }
}
