<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * The topic a question was matched to, and enough of the working to argue with.
 *
 * The runners-up are carried along even though nothing acts on them. The
 * complaint a knowledge base actually receives is "it answered the wrong thing"
 * or "it said it did not know", and neither can be investigated from the winner
 * alone: the useful fact is which topic came second and by how little. Without
 * it the only way to reproduce a bad match is to guess the visitor's wording,
 * which by then nobody remembers.
 */
final class KnowledgeVerdict
{
    /**
     * @param  list<string>  $roots  the roots of the question that matched
     * @param  list<array{topic_key: string, score: float}>  $alternatives  the runners-up, best first
     */
    public function __construct(
        private readonly KnowledgeEntry $entry,
        private readonly float $score,
        private readonly array $roots,
        private readonly array $alternatives = [],
    ) {}

    public function entry(): KnowledgeEntry
    {
        return $this->entry;
    }

    public function score(): float
    {
        return $this->score;
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * @return list<array{topic_key: string, score: float}>
     */
    public function alternatives(): array
    {
        return $this->alternatives;
    }
}
