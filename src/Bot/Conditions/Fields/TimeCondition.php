<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The time of day, for windows the bot's own hours do not describe.
 *
 * Both sides are padded to 'HH:MM' before they are compared, because an author
 * types "9:30" as often as "09:30" and as plain strings '9:30' sorts after
 * '10:00'. A branch meant to fire before ten in the morning would then fire
 * after it — right answer at 09:30 in the editor's head, wrong one in the
 * conversation, and nothing on the canvas to suggest which.
 */
final class TimeCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'time';
    }

    public function label(): string
    {
        return 'Time of day';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Clock;
    }

    public function hint(): ?string
    {
        return '18:00';
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, now()->format('H:i'), $this->clock($value));
    }

    private function clock(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $parts = explode(':', trim((string) $value));

        if ($parts[0] === '' || ! is_numeric($parts[0])) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $parts[0], (int) ($parts[1] ?? 0));
    }
}
