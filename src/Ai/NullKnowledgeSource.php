<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * No material for the model, which is the honest default.
 *
 * Until a host binds a real source, a node that answers with a model has
 * nothing to ground an answer in, and the only safe thing it can do is take its
 * "could not" exit. The alternative — letting the model answer from whatever it
 * remembers about the world — is a bot inventing an agency's refund policy.
 */
final class NullKnowledgeSource implements KnowledgeSource
{
    /**
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        return [];
    }
}
