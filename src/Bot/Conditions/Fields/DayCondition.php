<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The day of the month, for the branches every billing cycle needs.
 */
final class DayCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'day';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.day.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Number;
    }

    public function hint(): ?string
    {
        return __('chatbot::nodes.conditions.day.hint');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, (string) now()->day, $value);
    }
}
