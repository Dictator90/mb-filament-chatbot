<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * A run is a record of what happened, and nobody edits it.
 *
 * The refusals are absolute rather than deferred to the host, because a journal
 * an operator can edit is worth exactly as much as the memory of whoever last
 * opened it — which is nothing, on the only occasion anyone reads it.
 */
class RunPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'runs';
    }

    public function create(mixed $user): bool
    {
        return false;
    }

    public function update(mixed $user, Model $record): bool
    {
        return false;
    }
}
