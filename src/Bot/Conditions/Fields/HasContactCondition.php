<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Contracts\ContactDirectory;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Whether this thread points at a record in the host application.
 *
 * **Not the same question as `identified`, and the difference is worth the
 * second condition.** `identified` asks whether anybody proved who this is;
 * this asks whether the thread has been filed against something. The two come
 * apart in both directions, and each way round has a scenario that needs it:
 *
 * - **linked but not identified.** An operator attached the thread to a
 *   customer card by hand, or the host's directory recognised a phone number.
 *   The link is a working assumption, good enough to fetch an order's status
 *   into a variable and far short of reading somebody their own invoice.
 * - **identified but not linked.** The site signed the visitor's identity and
 *   the host keeps no record of them — a first-time buyer, or an installation
 *   with no customer table at all.
 *
 * So this is the condition in front of anything that *reads a record*, and
 * `identified` stays the condition in front of anything *personal*. A scenario
 * that wants both says both, which reads on the canvas exactly as it means.
 *
 * The morph is asked, never followed. What the record is belongs to the host
 * ({@see ContactDirectory}); all this needs to
 * know is that there is one.
 */
final class HasContactCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'has_contact';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.has_contact.label');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $linked = filled($conversation->contact_type) && filled($conversation->contact_id);

        return $this->compare($operator, $linked ? '1' : '0', $value);
    }
}
