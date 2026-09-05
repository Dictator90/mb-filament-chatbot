<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a run journal. Readable by whoever may read runs, writable by
 * nobody, for the reason the run itself is.
 */
class RunStepPolicy extends ChatbotPolicy
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
