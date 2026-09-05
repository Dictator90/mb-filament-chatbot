<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

/**
 * Cuts a sentence down to the few word beginnings worth matching on.
 *
 * **The stemmer is a blunt five-character cut, and a real one would be worse
 * here.** A proper stemmer needs a dictionary and a rule set per language, and
 * this package is installed by people whose visitors write in languages nobody
 * on this side has thought about. A language-aware stemmer that ships with
 * rules for English only is not neutral: it stems one language well and leaves
 * every other one raw, so the same knowledge base behaves differently depending
 * on which language a topic happens to be written in — and nothing on any
 * screen says so. Truncation is equally crude everywhere. It collapses
 * "delivery"/"delivered"/"deliveries" the same way it collapses their
 * equivalents in Russian or German, it never invents a root a person could not
 * predict from the word, and when it groups two unrelated words the host fixes
 * it by writing a keyword rather than by learning a rule set.
 *
 * The stop words come from configuration for the same reason. They are the one
 * part of this that genuinely is language-specific, English ships as the
 * default because that is the only language the package can claim to speak, and
 * a host replaces the whole list with one setting.
 */
final class RootExtractor
{
    /**
     * Words shorter than this carry no meaning worth matching.
     *
     * Almost every language's articles, prepositions and pronouns are two
     * letters or fewer, and matching on them means matching on nothing.
     */
    private const MIN_LENGTH = 3;

    /**
     * How much of a word survives.
     *
     * Five is short enough to fold an inflected ending away and long enough to
     * keep "refund" apart from "return".
     */
    private const ROOT_LENGTH = 5;

    /**
     * The roots of a text, in order of first appearance and without repeats.
     *
     * Duplicates are dropped rather than counted. A topic that says "order"
     * four times is not four times more about orders, and a knowledge base
     * where the longest-written entry wins is a knowledge base that rewards
     * padding.
     *
     * @return list<string>
     */
    public function roots(?string $text): array
    {
        $roots = [];

        foreach ($this->words($text) as $word) {
            $root = mb_substr($word, 0, self::ROOT_LENGTH);

            if (! in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * The roots of several texts at once — a topic's question, keywords and
     * variants make one bag of roots, not three.
     *
     * @param  array<int, string|null>  $texts
     * @return list<string>
     */
    public function rootsOfAll(array $texts): array
    {
        return $this->roots(implode(' ', array_filter(
            $texts,
            static fn (?string $text): bool => $text !== null && $text !== '',
        )));
    }

    /**
     * The meaningful words of a text, lowercased and stripped of punctuation.
     *
     * The stop words are removed before truncation, never after: the list a
     * host writes holds whole words, and checking a cut-down root against it
     * would quietly stop matching the moment somebody adds a word longer than
     * five letters.
     *
     * @return list<string>
     */
    private function words(?string $text): array
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return [];
        }

        $stopWords = $this->stopWords();
        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < self::MIN_LENGTH || isset($stopWords[$word])) {
                continue;
            }

            $words[] = $word;
        }

        return $words;
    }

    /**
     * @return array<string, true>
     */
    private function stopWords(): array
    {
        $configured = config('chatbot.knowledge.stop_words', []);

        $words = [];

        foreach ((array) $configured as $word) {
            $word = mb_strtolower(trim((string) $word));

            if ($word !== '') {
                $words[$word] = true;
            }
        }

        return $words;
    }
}
