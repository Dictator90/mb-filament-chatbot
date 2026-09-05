<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Budgets;

/**
 * Permission to spend, taken before the money is spent.
 *
 * A reservation is not a receipt. It says an estimate has already been written
 * against the month, so that whatever asks next sees a smaller balance than
 * this caller did, and it has to be closed either way — with settle() when the
 * provider answered, with release() when it did not. Nothing here is optional
 * bookkeeping: an estimate that is never closed is a month that looks more
 * expensive than it was, and the budget runs out early for no reason anybody
 * can find.
 *
 * A denied reservation is an ordinary answer, not an exception. Running out of
 * budget is the same event as a provider being unreachable — the model did not
 * answer — and a scenario has one branch for that. Throwing would give the
 * cheapest, most predictable failure a louder path through the code than the
 * unpredictable one.
 */
final readonly class BudgetReservation
{
    private function __construct(
        public bool $allowed,
        public ?int $botId,
        public string $period,
        public int $estimatedTokens,
        public ?int $periodId = null,
        public ?string $reason = null,
    ) {}

    public static function granted(?int $botId, string $period, int $estimatedTokens, int $periodId): self
    {
        return new self(
            allowed: true,
            botId: $botId,
            period: $period,
            estimatedTokens: $estimatedTokens,
            periodId: $periodId,
        );
    }

    /**
     * @param  string  $reason  what to tell the journal, in words a person can act on
     */
    public static function denied(string $reason, ?int $botId = null, string $period = '', int $estimatedTokens = 0): self
    {
        return new self(
            allowed: false,
            botId: $botId,
            period: $period,
            estimatedTokens: $estimatedTokens,
            reason: $reason,
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return ! $this->allowed;
    }
}
