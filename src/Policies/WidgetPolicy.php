<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class WidgetPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'widgets';
    }
}
