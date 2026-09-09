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
 * The stop words come from configuration for the same reason. English ships as
 * the default because that is the only language the package can claim to speak,
 * and a host replaces the whole list with one setting.
 *
 * **A host that does know its language may replace the stemming as well**, by
 * extending this class, overriding {@see self::stem()} and binding the result
 * over `RootExtractor` in the container. Both readers take it through their
 * constructor, so one binding covers the lookup and the base's own index —
 * which is the whole point: stem one side and not the other and matching gets
 * worse, not better.
 *
 * That seam is not a hedge. It answers something the paragraph above gets wrong
 * the moment the host is not this package: truncation is only "equally crude
 * everywhere" while *both* sides of a comparison are truncated. An application
 * whose keyword lists were milled by a real stemmer for its own language stores
 * roots this class never produces, and the question and the topic are then cut
 * by two different rules. Nothing fails — matching simply stops finding things,
 * and the bot reads as one that does not know half of its own knowledge base.
 * Found exactly that way, installing this package into an application with a
 * Russian knowledge base: six of ten topics with written answers went unmatched.
 */
class RootExtractor
{
    /**
     * Words shorter than this carry no meaning worth matching.
     *
     * Almost every language's articles, prepositions and pronouns are two
     * letters or fewer, and matching on them means matching on nothing.
     */
    protected const int MIN_LENGTH = 3;

    /**
     * How much of a word survives the default stemming.
     *
     * Five is short enough to fold an inflected ending away and long enough to
     * keep "refund" apart from "return". A subclass that stems properly ignores
     * this.
     */
    protected const int ROOT_LENGTH = 5;

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
            $root = $this->stem($word);

            if ($root !== '' && ! in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * One word to one root — the only thing a host has to replace to teach this
     * class a language.
     *
     * Deliberately the smallest possible seam. Splitting, lowercasing, the
     * minimum length and the stop-word list all stay here, because those are the
     * parts that go wrong quietly when they are reimplemented: a subclass that
     * forgot to lowercase, or that split on a different character class, would
     * index the knowledge base by one rule and search it by another and there
     * would be nothing on any screen to say so.
     *
     * An empty answer drops the word. That is how a real stemmer says "this is
     * a form with nothing left of it" without the caller having to know which
     * words those are.
     */
    protected function stem(string $word): string
    {
        return mb_substr($word, 0, static::ROOT_LENGTH);
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
    protected function words(?string $text): array
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return [];
        }

        $stopWords = $this->stopWords();
        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < static::MIN_LENGTH || isset($stopWords[$word])) {
                continue;
            }

            $words[] = $word;
        }

        return $words;
    }

    /**
     * @return array<string, true>
     */
    protected function stopWords(): array
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
