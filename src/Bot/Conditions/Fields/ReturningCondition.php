<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\Concerns\RemembersPerConversation;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Has this person been here before.
 *
 * Answered from the contact the host linked, not from the thread: one person
 * writes from the site today and from Telegram tomorrow, and each channel opens
 * its own conversation. Counting threads on this channel alone would greet a
 * returning customer as a stranger every time they changed app.
 *
 * A conversation with no contact answers false without a query. "We do not know
 * who this is" cannot be "they have written before", and asking the database
 * about a null contact would match every other unlinked thread in the table.
 *
 * The lookup is memoised per conversation — see the trait for why that is keyed
 * by the object.
 */
final class ReturningCondition extends ConditionField
{
    use ComparesValues;
    use RemembersPerConversation;

    public static function key(): string
    {
        return 'returning';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.returning.label');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare($operator, $this->hasEarlierThread($conversation) ? '1' : '0', $value);
    }

    private function hasEarlierThread(Conversation $conversation): bool
    {
        if ($conversation->contact_type === null || $conversation->contact_id === null) {
            return false;
        }

        return (bool) $this->remember($conversation, static fn (): bool => Conversation::query()
            ->where('contact_type', $conversation->contact_type)
            ->where('contact_id', $conversation->contact_id)
            ->whereKeyNot($conversation->getKey())
            ->exists());
    }
}
