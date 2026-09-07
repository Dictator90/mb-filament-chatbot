<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Greeting;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The visitor said hello and nothing else.
 *
 * Worth a branch of its own because the two openings want opposite answers.
 * "Hi" is an invitation to say what the bot can do; "hi, where is my order" is a
 * question, and answering it with a menu is the most annoying thing a bot does.
 * A keyword branch cannot tell them apart — the second message contains the
 * first.
 */
final class OnlyGreetingCondition extends ConditionField
{
    use ComparesValues;

    public function __construct(private readonly Greeting $greeting) {}

    public static function key(): string
    {
        return 'only_greeting';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.only_greeting.label');
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $said = $runtime->value('answer');

        return $this->compare($operator, $this->greeting->isGreeting($said) ? '1' : '0', $value);
    }
}
