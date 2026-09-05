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
    public function __construct(private readonly RootExtractor $extractor) {}

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

        $asked = $this->extractor->roots($said);

        if ($asked === []) {
            return null;
        }

        $entries = $this->entries($botId, $category);

        if ($entries->isEmpty()) {
            return null;
        }

        $scored = $this->score($entries, $asked);

        if ($scored === []) {
            return null;
        }

        $best = $scored[0];

        if ($best['score'] < $this->threshold('min_score', 0.2)) {
            return null;
        }

        if ($this->tooClose($best['score'], $scored[1]['score'] ?? 0.0)) {
            return null;
        }

        return new KnowledgeVerdict(
            $best['entry'],
            $best['score'],
            $best['matched'],
            $this->alternatives($scored),
        );
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
            $roots[$index] = $this->extractor->rootsOfAll(array_merge(
                [$entry->question],
                array_map(static fn (mixed $word): string => (string) $word, (array) $entry->keywords),
                array_map(static fn (mixed $word): string => (string) $word, (array) $entry->variants),
            ));

            foreach ($roots[$index] as $root) {
                $frequency[$root] = ($frequency[$root] ?? 0) + 1;
            }
        }

        $scored = [];

        foreach ($entries as $index => $entry) {
            $matched = array_values(array_intersect($asked, $roots[$index]));

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
