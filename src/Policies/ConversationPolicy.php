<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class ConversationPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'conversations';
    }
}
