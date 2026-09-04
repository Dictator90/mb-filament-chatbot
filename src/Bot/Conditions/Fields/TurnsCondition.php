<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\Concerns\RemembersPerConversation;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * How many times the visitor has written in this conversation.
 *
 * The measure of a conversation going nowhere. A person on their sixth message
 * to a bot is not being helped, whatever each individual branch thought, and
 * this is the only condition that can see that — every other one looks at a
 * single moment.
 *
 * Counted, not memoised across the turn boundary: the count changes with every
 * incoming message, and a value cached beyond one turn would keep a scenario at
 * "two" forever. Within a turn it is memoised, because a scenario may well ask
 * about it in three consecutive branches.
 */
final class TurnsCondition extends ConditionField
{
    use ComparesValues;
    use RemembersPerConversation;

    public static function key(): string
    {
        return 'turns';
    }

    public function label(): string
    {
        return 'Messages from the visitor';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Number;
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $turns = (int) $this->remember(
            $conversation,
            static fn (): int => $conversation->messages()->incoming()->count(),
        );

        return $this->compare($operator, (string) $turns, $value);
    }
}
