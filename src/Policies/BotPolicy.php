<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class BotPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'bots';
    }
}
