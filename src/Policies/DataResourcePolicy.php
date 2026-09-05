<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

/**
 * Data resources decide what a bot may read out of the host's own records, so
 * they get their own subject rather than sharing one with the scenarios.
 *
 * An application that lets support staff write scenarios does not necessarily
 * want them approving which columns of the customer table a bot may quote.
 */
class DataResourcePolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'data_resources';
    }
}
