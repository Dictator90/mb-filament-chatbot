<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * One thing a model asked to have done, on its way back from the provider.
 *
 * The id is the provider's, not ours, and it travels back out with the result.
 * A model may ask for three things in one breath, and the only way it learns
 * which answer belongs to which question is this string: match them by order
 * and a conversation where the second tool failed reads as if the first one
 * did.
 *
 * The input is whatever the model composed, and nothing here has checked it.
 * It is an argument list assembled by a program that guesses — a date as
 * "завтра", a number as a word, a field invented wholesale — so it goes to the
 * capability gateway, which refuses a payload that does not fit the
 * description rather than adjusting it.
 */
final class AiToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input = [],
    ) {}
}
