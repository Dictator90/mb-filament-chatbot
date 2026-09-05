<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * Where the material for a model's answer comes from.
 *
 * Narrow on purpose, and narrower than the knowledge base's own search. The
 * model layer needs one thing — a handful of labelled fragments that might
 * answer this message — and asking the knowledge base's finder for it directly
 * would bind every node here to how topics happen to be looked up today:
 * keywords now, an index later, an embedding after that. Each of those changes
 * the finder's shape, and none of them changes what a prompt needs.
 *
 * It is also what makes the model layer testable without any knowledge base at
 * all, and what lets a host feed the model from somewhere else entirely — a
 * help desk, a manual, a product catalogue — without touching a node.
 *
 * The default binding is {@see NullKnowledgeSource}: no material, so every node
 * that needs some takes its "could not" exit. A package that answered from
 * nothing would be a package that invents answers.
 */
interface KnowledgeSource
{
    /**
     * Fragments worth showing the model, best first.
     *
     * The key is what the model is asked to name back, so it has to be stable
     * within the turn and meaningful to whoever reads the run journal
     * afterwards — an article slug, not a row offset.
     *
     * @param  int|null  $botId  null means material shared by every bot
     * @param  string|null  $said  the visitor's message, or null when there is nothing to match on
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array;
}
