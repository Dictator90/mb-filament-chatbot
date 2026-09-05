<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * Hands the knowledge base to the model as material it may quote.
 *
 * An adapter rather than a method on the finder, because the two answer
 * different questions. The finder decides whether one topic is what the visitor
 * asked about and refuses when it is not sure; this hands over several topics
 * precisely because it is not sure, and lets the model read them.
 *
 * Topics the bot is forbidden to answer are excluded here, not filtered later.
 * A refund date is off limits whether a person writes it or a model paraphrases
 * it, and passing the text in "just as context" is exactly how it ends up in the
 * answer with different wording and the same wrong promise.
 */
class KnowledgeBaseSource implements KnowledgeSource
{
    public function __construct(private readonly RootExtractor $extractor) {}

    /**
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        $roots = $this->extractor->roots((string) $said);

        if ($roots === [] || $limit < 1) {
            return [];
        }

        $entries = KnowledgeEntry::query()
            ->active()
            ->forBot($botId)
            ->whereNotIn('mode', [KnowledgeMode::Handoff->value, KnowledgeMode::Scenario->value])
            ->whereNotNull('answer')
            ->get();

        return $entries
            ->map(fn (KnowledgeEntry $entry): array => [
                'entry' => $entry,
                'overlap' => count(array_intersect($roots, $this->extractor->rootsOfAll($entry))),
            ])
            ->filter(fn (array $row): bool => $row['overlap'] > 0)
            ->sortByDesc('overlap')
            ->take($limit)
            ->values()
            ->map(fn (array $row): array => [
                'key' => (string) $row['entry']->topic_key,
                'title' => (string) $row['entry']->question,
                'text' => (string) $row['entry']->answer,
            ])
            ->all();
    }
}
