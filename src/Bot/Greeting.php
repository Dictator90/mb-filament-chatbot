<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

/**
 * Whether a message is only a greeting and nothing more.
 *
 * Worth telling apart because "hi" and "hi, where is my order" want completely
 * different answers, and a keyword scenario that fires on the second one would
 * fire on the first too. A bare greeting is an opening, not a question.
 *
 * The list is short and stays short. Every word added here is a word that stops
 * being able to start a real conversation, and "help" is the obvious trap: on
 * its own it looks like a pleasantry and is in fact the most urgent thing a
 * visitor ever types.
 *
 * **The words come from the translations, not from this file.** A greeting is
 * the one thing every visitor writes and the one thing no language shares, so a
 * list compiled in here is a list that is wrong everywhere the package is
 * installed in another language — and wrong in the quietest way there is. It was:
 * `lang/ru/bot.php` has carried the Russian openings since the file was written
 * and nothing read them, so «Привет» was not a greeting, the branch that answers
 * an opening with a menu was never taken, and the bot passed «Привет» to the
 * knowledge base and answered its own customer with "I cannot help with that".
 * Nothing failed, nothing was logged, and the scenario on the canvas looked
 * exactly right.
 */
class Greeting
{
    /** @var list<string>|null */
    protected ?array $words = null;

    public function isGreeting(?string $text): bool
    {
        $text = $this->normalise($text);

        return $text !== '' && in_array($text, $this->words(), true);
    }

    /**
     * Lets a host swap the list wholesale, over and above the translations —
     * for a shop whose visitors open with something the language file has no
     * business claiming is a greeting everywhere.
     *
     * @param  list<string>  $words
     */
    public function using(array $words): static
    {
        $this->words = $this->normaliseAll($words);

        return $this;
    }

    /**
     * Resolved on every call rather than kept, because the translator answers
     * for whichever locale is current and this object may well outlive one. A
     * list read once at construction would be the first request's language for
     * the rest of the process — which on a host serving two languages is a bot
     * that greets properly for some visitors and not for others, depending on
     * who happened to arrive first.
     *
     * @return list<string>
     */
    protected function words(): array
    {
        return $this->words ?? $this->normaliseAll((array) __('chatbot::bot.greetings'));
    }

    /**
     * @param  array<int|string, mixed>  $words
     * @return list<string>
     */
    protected function normaliseAll(array $words): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $word): string => $this->normalise(is_string($word) ? $word : ''),
            $words,
        )));
    }

    /**
     * Punctuation is stripped rather than matched, because "hello!!!" and
     * "hi..." are the same opening, and a list that tried to enumerate the
     * variants would be wrong by the next message.
     */
    protected function normalise(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));

        return trim(preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? '', " \t\n\r\0\x0B");
    }
}
