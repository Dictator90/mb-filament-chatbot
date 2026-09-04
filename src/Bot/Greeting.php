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
 */
class Greeting
{
    /** @var list<string> */
    protected array $words = [
        'hi', 'hey', 'hello', 'yo',
        'good morning', 'good afternoon', 'good evening', 'good day',
        'morning', 'evening',
    ];

    public function isGreeting(?string $text): bool
    {
        $text = $this->normalise($text);

        return $text !== '' && in_array($text, $this->words, true);
    }

    /**
     * Lets a host swap the list wholesale — the package ships English, and an
     * installation that speaks anything else needs its own.
     *
     * @param  list<string>  $words
     */
    public function using(array $words): static
    {
        $this->words = array_values(array_filter(array_map(
            fn (string $word): string => $this->normalise($word),
            $words,
        )));

        return $this;
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
