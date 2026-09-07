<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Which day of the week it is.
 *
 * Compared as a fixed three-letter key rather than a formatted name, because the
 * name changes with the application's locale and the branch an author drew on a
 * Monday would stop matching the moment somebody set the locale to French. The
 * key is the stored value; the label is what the editor shows.
 */
final class WeekdayCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'weekday';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.weekday.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Choice;
    }

    /**
     * @return array<string, string>
     */
    public function options(): ?array
    {
        return (array) __('chatbot::nodes.conditions.weekday.options');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $today = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][now()->dayOfWeekIso - 1];

        return $this->compare($operator, $today, $value);
    }
}
