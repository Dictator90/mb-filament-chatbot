<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The conversation has already been given to a person.
 *
 * The engine stops the bot when this is true, so a running scenario will not see
 * it — which makes this condition look useless until you notice where it is
 * actually needed: in a scenario resumed from a queued pause, and in a quality
 * check replaying a conversation that ends in a handover. Both run against a
 * conversation whose state has moved on since the branch was drawn.
 */
final class HandedOffCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'handed_off';
    }

    public function label(): string
    {
        return 'Already handed to a person';
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $handedOff = $conversation->bot_state === BotState::HandedOff;

        return $this->compare($operator, $handedOff ? '1' : '0', $value);
    }
}
