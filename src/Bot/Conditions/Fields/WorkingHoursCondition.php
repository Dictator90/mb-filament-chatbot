<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Is the bot inside its own working hours right now.
 *
 * The branch behind "we will answer in the morning" — the single most common
 * reason a scenario needs to know the time, and worth its own field so that
 * nobody has to rebuild it out of the clock condition and get the night shift
 * wrong.
 *
 * Both the window that crosses midnight and the empty window are handled by
 * `Bot::isWorkingHours()` and are not re-implemented here. A second copy of that
 * comparison is exactly how a bot ends up answering at the wrong hour in one
 * place and the right hour in another, with no way to tell from the canvas which
 * of the two the author was looking at.
 *
 * A conversation with no bot is treated as always open. The alternative is a
 * scenario that silently takes the "closed" branch forever on an installation
 * that never attached a bot row, and a bot that is never open is indisting-
 * uishable from a bot that is broken.
 */
final class WorkingHoursCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'working_hours';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.working_hours.label');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $working = $conversation->bot?->isWorkingHours() ?? true;

        return $this->compare($operator, $working ? '1' : '0', $value);
    }
}
