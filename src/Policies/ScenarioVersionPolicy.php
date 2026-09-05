<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * A published version is a record of what was released, and it is written by
 * publishing rather than by editing.
 *
 * Deleting one is refused outright: a version is what a live conversation is
 * still running on, and what an incident review reads afterwards. Rolling back
 * issues a new version instead, so nothing is ever lost to make room.
 */
class ScenarioVersionPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'scenarios';
    }

    public function update(mixed $user, Model $record): bool
    {
        return false;
    }

    public function delete(mixed $user, Model $record): bool
    {
        return false;
    }
}
