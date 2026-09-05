<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class QuickReplyPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'quick_replies';
    }
}
