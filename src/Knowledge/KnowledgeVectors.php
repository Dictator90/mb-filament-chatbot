<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * Finds a topic by how the question *looks*, when matching its words has failed.
 *
 * **Why this sits next to the root search rather than replacing it.** Matching
 * roots either recognises a word or does not. "opening hours" and "openinghours"
 * are different things to it; "insurnace" matches nothing at all; and it is
 * silent on any question whose every word is unknown to the base. This picks up
 * that silence: closeness is measured over pieces of letters, so a typo, an
 * unexpected inflection or two words run together spoil the score without
 * cancelling it.
 *
 * **The vectors are computed here rather than fetched from a provider, and that
 * is a decision, not a shortcut.** Embeddings from a model would score better,
 * and they would make the knowledge base stop working with the model switched
 * off — turning the one part of the bot that answers customers into another
 * dependency on somebody else's service. A model is an optional layer in this
 * package. Everything a customer's answer rests on has to work without it.
 *
 * **Computed per request rather than stored in a table.** A base is topics in
 * the low hundreds, parsing them takes milliseconds, and the search that calls
 * this has already loaded them. A vector table would bring reindexing on every
 * edit, a queue to do it in, and a second place for the text and its index to
 * disagree — for a quality difference that does not exist at this size. Grow to
 * thousands and storage becomes the right answer; here it would be machinery
 * for its own sake.
 *
 * The idea is TF-IDF's: a rare piece weighs more than a common one. "insur"
 * appears in three topics and separates them; "ing" appears in half of them and
 * separates nothing.
 */
final class KnowledgeVectors
{
    /**
     * The size of a piece, in characters.
     *
     * Three, because inflections are shorter than that in most languages this
     * has to work in: "insurance" and "insurances" share eight triples out of
     * nine, and five quadruples out of eight. Pairs collapse into noise — "st"
     * is in every other word.
     */
    private const GRAM = 3;

    /**
     * Every piece's weight: the rarer it is, the more it tells apart.
     *
     * @var array<string, float>|null
     */
    private ?array $weights = null;

    /**
     * Parsed topics: the entry's key to its vector.
     *
     * @var array<int, array<string, float>>
     */
    private array $vectors = [];

    /**
     * The topics closest to what was said, closest first.
     *
     * An empty list means "nothing resembles this". Distant topics are dropped
     * by the threshold rather than returned as the best of a bad set — this
     * search runs where the root match went silent, which is where the bot
     * would otherwise say honestly that it does not know and fetch a person.
     * Answering the wrong topic there is worse than saying nothing: a confident
     * reply about something the visitor did not ask is taken for the truth,
     * while "let me get a colleague" is understood for what it is.
     *
     * @param  iterable<KnowledgeEntry>  $entries
     * @return list<array{entry: KnowledgeEntry, score: float}>
     */
    public function nearest(?string $said, iterable $entries, int $limit = 3): array
    {
        /*
         * Weights first, and the order is not taste. A question vector built
         * before them gets one per piece, while topics are already weighted:
         * the cosine then compares two different systems of measurement and
         * falls under the threshold. On a live base this showed up as the first
         * question never being recognised and the same question, asked second,
         * being recognised.
         */
        $this->weigh($entries);

        $asked = $this->vectorOf((string) $said);

        if ($asked === []) {
            return [];
        }

        $near = $this->threshold();
        $scored = [];

        foreach ($entries as $entry) {
            $score = $this->cosine($asked, $this->entryVector($entry));

            if ($score >= $near) {
                $scored[] = ['entry' => $entry, 'score' => $score];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * A topic's vector comes from its question, its wordings and its keywords —
     * and deliberately **not from its answer**.
     *
     * The answer is long and is about something else. In a topic called "what
     * the insurance covers" it lists hospitalisation, excess and assistance,
     * and the question "what is included in the tour?" would land closer to
     * that answer than to its own topic, purely because a long text resembles
     * everything a little. A question is matched against a question.
     *
     * @return array<string, float>
     */
    private function entryVector(KnowledgeEntry $entry): array
    {
        return $this->vectors[(int) $entry->getKey()] ??= $this->vectorOf($this->textOf($entry));
    }

    private function textOf(KnowledgeEntry $entry): string
    {
        return implode(' ', $entry->searchableTexts());
    }

    /**
     * Text into a vector of weighted pieces.
     *
     * Words are wrapped in spaces on purpose: the beginning and the end of a
     * word then produce pieces of their own, and "payment" and "repayment" stop
     * being nearly the same thing.
     *
     * @return array<string, float>
     */
    private function vectorOf(string $text): array
    {
        $text = ' '.trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text))).' ';
        $length = mb_strlen($text);

        if ($length < self::GRAM) {
            return [];
        }

        $counts = [];

        for ($at = 0; $at + self::GRAM <= $length; $at++) {
            $gram = mb_substr($text, $at, self::GRAM);
            $counts[$gram] = ($counts[$gram] ?? 0) + 1;
        }

        $vector = [];

        foreach ($counts as $gram => $count) {
            $vector[$gram] = $count * ($this->weights[$gram] ?? 1.0);
        }

        return $vector;
    }

    /**
     * How much each piece is worth across the whole base: rare separates,
     * common does not.
     *
     * Counted once per request and before any vector, because the weight is
     * needed while the first one is being built and depends on the entire base
     * at once. The counting itself runs on unweighted pieces — only their names
     * matter there.
     *
     * @param  iterable<KnowledgeEntry>  $entries
     */
    private function weigh(iterable $entries): void
    {
        if ($this->weights !== null) {
            return;
        }

        $seen = [];
        $topics = 0;

        foreach ($entries as $entry) {
            $topics++;

            foreach (array_keys($this->vectorOf($this->textOf($entry))) as $gram) {
                $seen[$gram] = ($seen[$gram] ?? 0) + 1;
            }
        }

        $this->weights = [];

        foreach ($seen as $gram => $count) {
            $this->weights[$gram] = log(1 + $topics / $count);
        }
    }

    /**
     * The cosine between two vectors: one is the same thing, zero is nothing in
     * common.
     *
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $dot = 0.0;

        foreach ($left as $gram => $weight) {
            $dot += $weight * ($right[$gram] ?? 0.0);
        }

        if ($dot === 0.0) {
            return 0.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $w): float => $w * $w, $left)))
            * sqrt(array_sum(array_map(static fn (float $w): float => $w * $w, $right)));

        return $norm > 0.0 ? $dot / $norm : 0.0;
    }

    /**
     * How close is close enough to call a topic found.
     *
     * High on purpose, for the reason given on `nearest()`. Configurable
     * because the right number depends on the language and on how alike the
     * topics of a particular base are, and no default can be right for both a
     * base of twelve topics and one of three hundred.
     */
    private function threshold(): float
    {
        $value = config('chatbot.knowledge.min_similarity', 0.42);

        return is_numeric($value) ? (float) $value : 0.42;
    }
}
