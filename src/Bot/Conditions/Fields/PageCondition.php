<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The page the visitor opened the chat from.
 *
 * The cheapest context a widget ever provides: somebody writing from the pricing
 * page is asking about price, and a scenario can skip a question because of it.
 *
 * The page where the conversation started, not where they are now. A visitor
 * keeps browsing while they type, and branching on the current page would make
 * the same question take different routes depending on how long they took to
 * write it.
 */
final class PageCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'page';
    }

    public function label(): string
    {
        return 'Page the chat was opened from';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Text;
    }

    public function hint(): ?string
    {
        return '/pricing';
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, $conversation->entry_page, $value);
    }
}
