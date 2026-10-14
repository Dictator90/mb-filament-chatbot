<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

/**
 * What kind of answer a slot expects, and therefore how it is read.
 *
 * The kind decides parsing, not presentation. People do not answer a question
 * about dates with a date: they write "next Friday", "the 3rd", "in two weeks".
 * A slot that only accepted an ISO date would reject almost every real answer
 * and hand the conversation to a person over a formatting problem.
 */
enum SlotKind: string
{
    /** One line of free text, taken as written. */
    case Text = 'text';

    /** Several sentences — a complaint, a description of a problem. */
    case LongText = 'long_text';

    /**
     * What to call the person, taken out of the sentence they answer with.
     *
     * Almost nobody answers "как вас зовут?" with one word. They answer «меня
     * зовут Ирина», «я Пётр», "it's Nina" — and a free-text slot keeps the
     * sentence. The bot then says «Меня зовут Ирина, оформляю заявку на
     * подбор», the same string goes into the customer card, and a manager
     * rings somebody whose first name is a greeting.
     */
    case Name = 'name';

    /**
     * A phone number.
     *
     * Normalised on the way in, because the same person writes the same number
     * five different ways and the host is expected to match on it.
     */
    case Phone = 'phone';

    case Email = 'email';

    /** A plain count — people, nights, items. */
    case Number = 'number';

    /** An amount of money, with the separators and currency words stripped. */
    case Money = 'money';

    case Date = 'date';

    /**
     * A span, from one date to another.
     *
     * Its own kind rather than two date slots because that is how people answer:
     * "from the 10th to the 17th" arrives in one message, and asking twice for
     * something already said is the fastest way to make a bot feel like a form.
     */
    case DateRange = 'date_range';

    /** One of a fixed list, matched by label or by number. */
    case Choice = 'choice';

    /**
     * Whether an unreadable answer is worth asking about again.
     *
     * Free text never is: whatever the person wrote is the answer. Everything
     * else has a shape that can be missed, and one re-ask is the difference
     * between a typo and a lost conversation.
     */
    public function canBeMisread(): bool
    {
        return ! in_array($this, [self::Text, self::LongText], true);
    }
}
