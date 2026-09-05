<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

class QualityTestPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'quality_checks';
    }
}
