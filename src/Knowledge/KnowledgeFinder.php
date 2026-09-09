<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * Finds the one topic a question is about, or honestly finds none.
 *
 * **A root is worth what it is rare.** Counting matched words equally is the
 * obvious way to score, and it fails on the only questions people actually ask:
 * "where is my refund for this order" contains "order", which half a shop's
 * knowledge base also contains, and "refund", which exactly one topic contains.
 * Counted flat, the two topics tie and the answer is a coin toss between the
 * refund policy and the order tracker. Weighting each root by one over the
 * number of topics carrying it makes the rare word decide, which is what it was
 * doing in the sentence.
 *
 * The frequencies are counted over the topics this bot may use, not over some
 * global table, because that is the population a question is being matched
 * against: a word is common or rare relative to the base it has to pick from.
 */
final class KnowledgeFinder
{
    public function __construct(
        private readonly RootExtractor $extractor,
        private readonly KnowledgeVectors $vectors,
    ) {}

    /**
     * The topic behind a question, or null when the base does not know.
     *
     * @param  string|null  $category  narrows the search to one section of the base
     */
    public function find(?int $botId, ?string $said, ?string $category = null): ?KnowledgeVerdict
    {
        if (! config('chatbot.knowledge.enabled', true)) {
            return null;
        }

        $entries = $this->entries($botId, $category);

        if ($entries->isEmpty()) {
            return null;
        }

        $asked = $this->extractor->roots($said);
        $scored = $asked === [] ? [] : $this->score($entries, $asked);
        $best = $scored[0] ?? null;

        if ($best === null || $best['score'] < $this->threshold('min_score', 0.2)) {
            return $this->byShape($said, $entries);
        }

        if ($this->tooClose($best['score'], $scored[1]['score'] ?? 0.0)) {
            return $this->settle($said, $entries, $scored);
        }

        return new KnowledgeVerdict(
            $best['entry'],
            $best['score'],
            $best['matched'],
            $this->alternatives($scored),
        );
    }

    /**
     * The second pass: what the question looks like, once matching its words
     * has found nothing.
     *
     * Reached from two silences that look different and are the same. Either no
     * topic shares a single root with the question — a typo, two words run
     * together, an inflection the extractor does not strip — or one does and so
     * weakly that the score is noise. Both end with the bot saying it does not
     * know, and both are frequently a question the base can answer perfectly
     * well ({@see KnowledgeVectors}).
     *
     * The verdict carries no matched roots, because there were none. That is
     * not a gap to fill with something plausible: the journal shows how the
     * topic was found, and a list of roots invented here would make a shape
     * match indistinguishable from a word match when somebody comes to ask why
     * this topic was chosen.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     */
    private function byShape(?string $said, Collection $entries): ?KnowledgeVerdict
    {
        if (! config('chatbot.knowledge.similarity', true)) {
            return null;
        }

        $nearest = $this->vectors->nearest(
            $said,
            $entries,
            max(1, (int) config('chatbot.knowledge.candidates', 5)),
        );

        if ($nearest === []) {
            return null;
        }

        return new KnowledgeVerdict(
            $nearest[0]['entry'],
            $nearest[0]['score'],
            [],
            array_values(array_map(
                static fn (array $row): array => [
                    'topic_key' => (string) $row['entry']->topic_key,
                    'score' => round($row['score'], 4),
                ],
                array_slice($nearest, 1),
            )),
            KnowledgeVerdict::BY_SHAPE,
        );
    }

    /**
     * A tie between topics that matched the words equally, settled by shape —
     * but only when shape is not itself in two minds.
     *
     * The tie rule exists because two topics fitting a question equally usually
     * means the word that would separate them is missing, and answering either
     * is a confident reply about something nobody asked. It is not always that,
     * though: roots are compared truncated, so "shipp internationaly" ties the
     * shipping topic against the international one on a prefix, while the full
     * spelling separates them six to one. Refusing there loses a question the
     * base can answer, in favour of a safety that was not being threatened.
     *
     * Two conditions keep it safe, and both are needed:
     *
     * - **the winner has to be one of the tied topics.** Shape may break a tie,
     *   never overturn it: a third topic arriving from nowhere would be the
     *   word search overruled by the weaker pass.
     * - **shape has to be decisive by the same margin the words failed by.**
     *   Two topics close on words and close on shape are genuinely
     *   indistinguishable, and that is the case the honest "I do not know"
     *   was written for.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     * @param  list<array{entry: KnowledgeEntry, score: float, matched: list<string>}>  $scored
     */
    private function settle(?string $said, Collection $entries, array $scored): ?KnowledgeVerdict
    {
        $shape = $this->byShape($said, $entries);

        if ($shape === null) {
            return null;
        }

        $tied = array_map(
            static fn (array $row): string => (string) $row['entry']->topic_key,
            array_values(array_filter(
                $scored,
                static fn (array $row): bool => $row['score'] > 0.0,
            )),
        );

        if (! in_array((string) $shape->entry()->topic_key, $tied, true)) {
            return null;
        }

        $runnerUp = (float) ($shape->alternatives()[0]['score'] ?? 0.0);

        return $this->tooClose($shape->score(), $runnerUp) ? null : $shape;
    }

    /**
     * Every topic in play, read in one query.
     *
     * One query, and not one per topic to count a word in it: the cost of
     * answering a question has to stay flat as the base grows, or the knowledge
     * base becomes slower precisely for the hosts who use it most. Ordering by
     * priority makes the read deterministic, so a tie that survives scoring
     * still resolves the same way twice — and PHP's stable sort keeps that
     * order intact below.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    private function entries(?int $botId, ?string $category): Collection
    {
        $category = $category === null ? null : trim($category);

        return KnowledgeEntry::query()
            ->active()
            ->forBot($botId)
            ->when($category !== null && $category !== '', fn ($query) => $query->where('category', $category))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every topic that shares at least one root with the question, best first.
     *
     * The sum is divided by the number of roots in the question, not by the
     * number in the topic. Dividing by the topic's own size would be a second
     * penalty on top of the frequency weighting and would push a thorough topic
     * with many keywords below a thin one; dividing by the question's size is
     * what makes two questions of different lengths comparable against the same
     * threshold at all.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     * @param  list<string>  $asked
     * @return list<array{entry: KnowledgeEntry, score: float, matched: list<string>}>
     */
    private function score(Collection $entries, array $asked): array
    {
        $roots = [];
        $frequency = [];

        foreach ($entries as $index => $entry) {
            $roots[$index] = $this->extractor->rootsOfAll($entry->searchableTexts());

            foreach ($roots[$index] as $root) {
                $frequency[$root] = ($frequency[$root] ?? 0) + 1;
            }
        }

        $scored = [];

        foreach ($entries as $index => $entry) {
            $matched = $this->matched($entry, $asked, $roots[$index]);

            if ($matched === []) {
                continue;
            }

            $weight = 0.0;

            foreach ($matched as $root) {
                $weight += 1 / $frequency[$root];
            }

            $scored[] = [
                'entry' => $entry,
                'score' => $weight / count($asked),
                'matched' => $matched,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * The roots of a topic that the question actually reached — with a
     * multi-word keyword counting only when the question contains all of it.
     *
     * **A keyword of several words is a phrase, and treating it as separate
     * roots throws away the one tool an author has for pulling two topics
     * apart.** "Where is the hotel" and "where is your office" both reduce to
     * one root; the author separates them by writing "hotel located" on the
     * first, meaning *both* words. Split into "hotel" and "located", it claims
     * every question with either — so the hotel topic ties with the office topic
     * on a question that never mentioned a hotel, the tie rule fires, and the
     * bot answers neither. The author then sees their disambiguation ignored,
     * with nothing to say why.
     *
     * Found on a live base whose author had already done this work by hand:
     * "dog hotel", "cat hotel", "pet hotel" on the accommodation topic, exactly
     * so that a bare "can I bring a dog?" would go to the travel topic that
     * answers it. Every one of those pairs was being read as two loose roots.
     *
     * Frequency is deliberately not touched: it still counts every root a topic
     * mentions anywhere. Making a phrase's roots rare would hand a matched
     * phrase an enormous weight and turn a disambiguation tool into a way of
     * winning every tie.
     *
     * @param  list<string>  $asked
     * @param  list<string>  $all  every root of the topic, phrases flattened
     * @return list<string>
     */
    private function matched(KnowledgeEntry $entry, array $asked, array $all): array
    {
        $phrases = [];
        $inPhrases = [];

        foreach ((array) $entry->keywords as $keyword) {
            $roots = $this->extractor->roots((string) $keyword);

            if (count($roots) < 2) {
                continue;
            }

            $phrases[] = $roots;

            foreach ($roots as $root) {
                $inPhrases[$root] = true;
            }
        }

        if ($phrases === []) {
            return array_values(array_intersect($asked, $all));
        }

        /*
         * A root that also appears loose somewhere else in the topic — in its
         * question, in a variant, in a single-word keyword — stays loose. The
         * phrase is a way of adding a narrow claim, never of withdrawing a
         * claim the topic already makes plainly.
         */
        $loose = $this->extractor->rootsOfAll(array_merge(
            [(string) $entry->question],
            array_map(static fn (mixed $word): string => (string) $word, (array) $entry->variants),
            array_values(array_filter(
                array_map(static fn (mixed $word): string => (string) $word, (array) $entry->keywords),
                fn (string $keyword): bool => count($this->extractor->roots($keyword)) < 2,
            )),
        ));

        $reached = array_values(array_intersect($asked, $loose));

        foreach ($phrases as $roots) {
            if (array_diff($roots, $asked) === []) {
                $reached = array_merge($reached, $roots);
            }
        }

        return array_values(array_unique($reached));
    }

    /**
     * Whether the runner-up is close enough to make the winner a guess.
     *
     * Two topics that fit a question equally well do not mean the base has two
     * right answers. They mean the question was not the question we think it
     * was — the words that would have separated the topics are missing from it
     * — and picking either one produces a confident reply about something the
     * person did not ask. That is the worst failure a knowledge base has,
     * because it reads as an answer: the visitor believes it, quotes it back,
     * and nobody looks at the base again. "I do not know" routes to a person
     * and costs one message.
     *
     * The margin is relative to the winner's own score, so it means the same
     * thing for a strong match and a weak one.
     */
    private function tooClose(float $best, float $runnerUp): bool
    {
        if ($runnerUp <= 0.0 || $best <= 0.0) {
            return false;
        }

        return ($best - $runnerUp) / $best < $this->threshold('min_gap', 0.25);
    }

    /**
     * @param  list<array{entry: KnowledgeEntry, score: float, matched: list<string>}>  $scored
     * @return list<array{topic_key: string, score: float}>
     */
    private function alternatives(array $scored): array
    {
        $candidates = max(1, (int) config('chatbot.knowledge.candidates', 5));

        return array_values(array_map(
            static fn (array $row): array => [
                'topic_key' => (string) $row['entry']->topic_key,
                'score' => round($row['score'], 4),
            ],
            array_slice($scored, 1, $candidates - 1),
        ));
    }

    private function threshold(string $key, float $default): float
    {
        $value = config('chatbot.knowledge.'.$key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
