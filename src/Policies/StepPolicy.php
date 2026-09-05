<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

/**
 * A node belongs to a scenario and is edited on its canvas, so it answers to the
 * scenario's ability rather than one of its own.
 */
class StepPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'scenarios';
    }
}
