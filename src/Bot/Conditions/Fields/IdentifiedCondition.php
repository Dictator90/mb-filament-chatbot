<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Has the host told us who this person is.
 *
 * The gate in front of anything personal. It asks the conversation rather than
 * looking for a phone number, because a phone number typed into the widget was
 * typed by whoever was at the keyboard — see `Conversation::isIdentified()`. A
 * branch built on "we have a phone number" would read somebody their own data
 * only by luck.
 */
final class IdentifiedCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'identified';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.identified.label');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, $conversation->isIdentified() ? '1' : '0', $value);
    }
}
