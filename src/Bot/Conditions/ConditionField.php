<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions;

use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * One thing a condition node can branch on.
 *
 * Registered rather than enumerated, for the same reason step types are: what a
 * conversation can be asked about depends entirely on the business. The package
 * knows about the conversation itself — its channel, whether the person has
 * written before, what the bot has collected, what time it is. Whether they have
 * an unpaid invoice is the host's question, and the host adds it.
 *
 * Conditions are read-only by contract. A condition that wrote anything would
 * run a different number of times depending on how a scenario branches, and an
 * author reading the canvas has no way to see that.
 */
abstract class ConditionField
{
    /**
     * The value stored in a scenario's saved branch.
     *
     * Stable forever once used, and prefixed for host fields — "crm.has_debt"
     * rather than "has_debt" — so that a later package cannot take the name out
     * from under a published scenario.
     */
    abstract public static function key(): string;

    abstract public function label(): string;

    /**
     * Answers the condition for this conversation.
     *
     * The operator and value come from the branch the author drew. An operator
     * this field never offered is not something to guess at: answer false and
     * let the branch fall through to "otherwise", rather than inventing a
     * comparison the author did not ask for.
     */
    abstract public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool;

    /**
     * What shape of comparison this field supports, which decides the operators
     * the editor offers and whether it draws a value box at all.
     */
    public function kind(): ConditionKind
    {
        return ConditionKind::Flag;
    }

    /**
     * Whether the branch also needs a name alongside the value.
     *
     * True only for fields that are really a family — "a collected variable" is
     * not one condition but as many as there are variables, and the author has
     * to say which.
     */
    public function needsName(): bool
    {
        return false;
    }

    /**
     * A fixed list of values to compare against, when there is one.
     *
     * @return array<string, string>|null
     */
    public function options(): ?array
    {
        return null;
    }

    /** Placeholder text for the value box, telling the author what it expects. */
    public function hint(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'value' => static::key(),
            'label' => $this->label(),
            'kind' => $this->kind()->value,
            'operators' => $this->kind()->operators(),
            'needs_name' => $this->needsName() ?: null,
            'options' => $this->options(),
            'hint' => $this->hint(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
