<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class KnowledgeEntryPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'knowledge';
    }
}
