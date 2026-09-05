<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Budgets;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\BudgetPeriod;
use RuntimeException;

/**
 * The monthly spending limit, enforced before the money leaves.
 *
 * Every rule in this class exists because the obvious implementation — add up
 * what was spent, compare, then call the provider — is wrong in a way that only
 * shows up under load. Two conversations arriving in the same second both read
 * the same remaining balance, both decide there is room, and both spend it; the
 * month ends over budget with a ledger that says it never was.
 *
 * So the estimate is written first and the decision is the write itself. There
 * is no moment between the check and the charge for a second request to slip
 * into.
 */
final class BudgetGuard
{
    protected string $table = 'chatbot_budget_periods';

    /**
     * Takes room for a call out of this month before the call is made.
     *
     * The check and the charge are one conditional UPDATE. The estimate is
     * added to input_tokens only if the row still has room for it, and the
     * database reports whether the row changed: nothing is read into PHP,
     * compared there and written back, because that sequence is not atomic no
     * matter how short it is. The second of two simultaneous callers therefore
     * sees the first caller's estimate already in the column, which is the
     * entire point.
     *
     * Cost is checked as "has the month already reached its ceiling" rather
     * than reserved like tokens, because at this moment nobody knows what the
     * call will cost — the price depends on how long the answer turns out to
     * be. Tokens are the quantity that can honestly be estimated in advance,
     * and the cost ceiling stops the month one call later than the token one
     * would. Reserving a made-up price would be a more precise-looking number
     * that is wrong every time.
     *
     * With no budget configured the estimate is still written, and that is
     * deliberate. Accounting is not the same feature as limiting: the first
     * question anybody asks after switching AI on is what it cost, and an
     * installation that only counted once a limit existed would answer it with
     * nothing for the entire period before somebody thought to set one.
     */
    public function reserve(?Bot $bot, int $estimatedTokens): BudgetReservation
    {
        $estimatedTokens = max(0, $estimatedTokens);
        $period = $this->period();
        $botId = $bot?->getKey();
        $periodId = $this->periodId($botId, $period);

        $limits = $this->limits($bot);
        $query = $this->row($periodId);

        if ($limits['tokens'] !== null) {
            $query->whereRaw('input_tokens + output_tokens + ? <= ?', [$estimatedTokens, $limits['tokens']]);
        }

        if ($limits['cost'] !== null) {
            $query->where('cost_micro', '<', $limits['cost']);
        }

        $applied = $query->update([
            'input_tokens' => DB::raw('input_tokens + '.$estimatedTokens),
            'calls' => DB::raw('calls + 1'),
            'updated_at' => now(),
        ]);

        if ($applied === 0) {
            return BudgetReservation::denied(
                $this->refusal($limits, $period),
                $botId,
                $period,
                $estimatedTokens,
            );
        }

        return BudgetReservation::granted($botId, $period, $estimatedTokens, $periodId);
    }

    /**
     * Replaces the estimate with what the call actually used.
     *
     * The estimate is taken back out in the same statement that puts the real
     * figures in, so the row is never briefly counted twice — a concurrent
     * reserve reading it mid-settle would otherwise refuse a call there was
     * room for.
     *
     * calls is not touched here. It was raised when the reservation was taken,
     * and raising it again would count one request as two — the ratio of calls
     * to tokens is how an oversized prompt is spotted, and it is the first
     * number that stops making sense when a call is counted twice.
     *
     * A denied reservation settles into nothing. Nothing was reserved, no
     * provider was called, and writing usage against it would put spending in
     * the ledger that never happened.
     */
    public function settle(BudgetReservation $reservation, int $inputTokens, int $outputTokens, int $costMicro): void
    {
        if ($reservation->isDenied() || $reservation->periodId === null) {
            return;
        }

        $estimate = $reservation->estimatedTokens;

        $this->row($reservation->periodId)->update([
            'input_tokens' => DB::raw($this->subtractThenAdd('input_tokens', $estimate, max(0, $inputTokens))),
            'output_tokens' => DB::raw('output_tokens + '.max(0, $outputTokens)),
            'cost_micro' => DB::raw('cost_micro + '.max(0, $costMicro)),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gives the estimate back when the call never happened.
     *
     * The provider refused, the request timed out, the scenario took another
     * branch — in every one of those the month has been charged for something
     * nobody received. Left in place, a run of provider errors would exhaust a
     * budget without a single answer being generated, and the panel would show
     * a month spent on nothing.
     */
    public function release(BudgetReservation $reservation): void
    {
        if ($reservation->isDenied() || $reservation->periodId === null) {
            return;
        }

        $estimate = $reservation->estimatedTokens;

        $this->row($reservation->periodId)->update([
            'input_tokens' => DB::raw($this->subtractThenAdd('input_tokens', $estimate, 0)),
            'calls' => DB::raw('CASE WHEN calls > 0 THEN calls - 1 ELSE 0 END'),
            'updated_at' => now(),
        ]);
    }

    /**
     * What this bot has spent so far this month.
     */
    public function usage(?Bot $bot, ?string $period = null): BudgetPeriod
    {
        $period ??= $this->period();
        $botId = $bot?->getKey();

        return BudgetPeriod::query()->find($this->periodId($botId, $period))
            ?? new BudgetPeriod(['bot_id' => $botId, 'period' => $period]);
    }

    /**
     * The month a call belongs to, by the application's own clock.
     *
     * Application time, not UTC and not the visitor's: a budget is set by the
     * people who pay it, and they mean their own calendar month. Read from
     * now() so that a host running its application in another timezone gets its
     * own month boundaries rather than the package's opinion of them.
     */
    public function period(): string
    {
        return now()->format('Y-m');
    }

    /**
     * The limits in force for one bot: its own where it sets them, the
     * installation's otherwise.
     *
     * A limit of null means unlimited, and zero means zero — they are different
     * answers and the coalesce must not blur them. A bot budgeted at zero has
     * been switched off deliberately, and reading that as "fall back to the
     * installation default" would turn a deliberate stop into normal spending.
     *
     * Disabling budgets clears both limits rather than skipping this class:
     * usage keeps being recorded, only the refusal stops.
     *
     * @return array{tokens: int|null, cost: int|null}
     */
    protected function limits(?Bot $bot): array
    {
        if (! (bool) config('chatbot.budgets.enabled', false)) {
            return ['tokens' => null, 'cost' => null];
        }

        return [
            'tokens' => $this->limit($bot?->monthly_token_budget ?? config('chatbot.budgets.monthly_tokens')),
            'cost' => $this->limit($bot?->monthly_cost_budget ?? config('chatbot.budgets.monthly_cost')),
        ];
    }

    protected function limit(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int) $value);
    }

    /**
     * The id of this bot's row for this month, created if it is the first call.
     *
     * Rows are addressed by id from here on rather than by the pair of bot and
     * period, and the reason is a corner of SQL that would otherwise corrupt the
     * shared row silently: every engine treats NULLs as distinct in a unique
     * index, so the unique constraint does not protect the installation-wide
     * row whose bot_id is null. Two first calls of a month could create two of
     * them, and an UPDATE matched on the pair would then charge both — one call
     * counted twice, in the one row nobody watches. Holding an id means the
     * worst a duplicate can do is split a month's total, which is visible.
     *
     * The insert is attempted and its failure ignored rather than guarded by a
     * prior existence check, because between the check and the insert is exactly
     * where the other request creates the row.
     */
    protected function periodId(?int $botId, string $period): int
    {
        $existing = $this->find($botId, $period);

        if ($existing !== null) {
            return $existing;
        }

        try {
            DB::table($this->table)->insert([
                'bot_id' => $botId,
                'period' => $period,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_micro' => 0,
                'calls' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Another request created the row between the lookup and the insert.
        }

        return $this->find($botId, $period)
            ?? throw new RuntimeException("Could not open a budget period for [{$period}].");
    }

    protected function find(?int $botId, string $period): ?int
    {
        $id = DB::table($this->table)
            ->where('period', $period)
            ->when($botId === null,
                fn (Builder $query): Builder => $query->whereNull('bot_id'),
                fn (Builder $query): Builder => $query->where('bot_id', $botId),
            )
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    protected function row(int $periodId): Builder
    {
        return DB::table($this->table)->where('id', $periodId);
    }

    /**
     * Takes an amount off a counter without letting it go below zero.
     *
     * Written as CASE rather than with GREATEST or MAX, which are spelled
     * differently on every engine the package supports. The columns are
     * unsigned, so an underflow is not a small wrong number — it is a query
     * that fails on MySQL in strict mode and a very large number on SQLite,
     * neither of which anybody would connect to a released reservation.
     */
    protected function subtractThenAdd(string $column, int $subtract, int $add): string
    {
        return "CASE WHEN {$column} >= {$subtract} THEN {$column} - {$subtract} ELSE 0 END + {$add}";
    }

    /**
     * @param  array{tokens: int|null, cost: int|null}  $limits
     */
    protected function refusal(array $limits, string $period): string
    {
        $reached = [];

        if ($limits['tokens'] !== null) {
            $reached[] = "the token budget of {$limits['tokens']}";
        }

        if ($limits['cost'] !== null) {
            $reached[] = "the cost budget of {$limits['cost']} micro-units";
        }

        return $reached === []
            ? "No budget period could be charged for {$period}."
            : 'This month has reached '.implode(' or ', $reached).". Raise it on the bot or in chatbot.budgets, or wait for {$period} to end.";
    }
}
