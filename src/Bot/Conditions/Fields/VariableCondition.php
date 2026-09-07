<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Conditions\ReceivesName;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Branches on something the bot collected.
 *
 * The one condition every scenario ends up using, and the reason `needsName()`
 * exists at all: the author picks the variable, not the package.
 *
 * The value is read through the runtime rather than out of `bot_answers`
 * directly, so that a branch sees exactly what a message template would. Read
 * from the column instead and a condition on `{{talk.answer}}` — the thing the
 * visitor just typed, which is not on the row yet — would always answer "never
 * collected" while the sentence beside it printed the text.
 */
final class VariableCondition extends ConditionField implements ReceivesName
{
    use ComparesValues;

    private ?string $name = null;

    public static function key(): string
    {
        return 'variable';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.variable.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Text;
    }

    public function needsName(): bool
    {
        return true;
    }

    public function hint(): ?string
    {
        return __('chatbot::nodes.conditions.variable.hint');
    }

    public function withName(?string $name): static
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        if ($this->name === null || trim($this->name) === '') {
            return false;
        }

        return $this->compare($operator, $runtime->value(trim($this->name)), $value);
    }
}
