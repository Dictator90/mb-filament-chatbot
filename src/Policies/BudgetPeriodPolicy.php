<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * What a bot has spent this month, and nobody edits it.
 *
 * The refusals are absolute rather than deferred to the host for the same reason
 * a run's are: a spend figure somebody can correct by hand is not a spend
 * figure. Reading it is ordinary — it is the answer to "why did the bot stop
 * answering" — so viewing follows the host's opinion like everything else.
 */
class BudgetPeriodPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'budgets';
    }

    public function create(mixed $user): bool
    {
        return false;
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
