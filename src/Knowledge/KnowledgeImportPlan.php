<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

/**
 * What will happen to the knowledge base if this file is loaded.
 *
 * Parsed apart from writing, because whoever is loading it has to see the plan
 * before anything happens. A base is weeks of somebody's writing, and "load it
 * and see" there means losing that silently.
 *
 * The same object is built twice — once for the preview in the dialog, once
 * inside the action itself — and that is cheaper than keeping a plan between
 * requests and hoping the file in the component's state is still the one the
 * preview described.
 */
final class KnowledgeImportPlan
{
    /**
     * @param  list<array{topic_key: string, attributes: array<string, mixed>}>  $rows
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $warnings = [],
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $skipped = 0,
        public readonly int $keptAnswers = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * A short summary, for the dialog and for the notice after writing.
     *
     * "answers left as they were" is not decoration on a counter. It is the one
     * place the decision about an empty cell is visible ({@see KnowledgeExchange}):
     * whoever loaded the file has to learn that twelve empty cells erased
     * nothing — rather than discover it a month later.
     */
    public function summary(): string
    {
        $parts = [
            __('chatbot::panel.knowledge.exchange.plan_created', ['count' => $this->created]),
            __('chatbot::panel.knowledge.exchange.plan_updated', ['count' => $this->updated]),
        ];

        if ($this->skipped > 0) {
            $parts[] = __('chatbot::panel.knowledge.exchange.plan_skipped', ['count' => $this->skipped]);
        }

        if ($this->keptAnswers > 0) {
            $parts[] = __('chatbot::panel.knowledge.exchange.plan_kept', ['count' => $this->keptAnswers]);
        }

        return implode(', ', $parts).'.';
    }
}
