<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Widgets;

use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MB\FilamentChatbot\Budgets\BudgetGuard;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Filament\Widgets\Concerns\ReadsChatStatistics;
use MB\FilamentChatbot\Models\BudgetPeriod;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Run;

/**
 * Four numbers about the chat, for a host's dashboard.
 *
 * Each tile answers a question somebody actually asks, and there are four
 * because that is how many such questions there are: is the chat busier than it
 * was, is the bot coping, is anybody waiting right now, and what is the month
 * costing. A fifth tile would be filling the row.
 *
 * Everything here is counted with aggregates and never by walking rows. A
 * dashboard is drawn on every load of the panel, by every member of staff, and
 * a widget that fetched conversations to count them would make the whole panel
 * slower in exact proportion to how well the chat is doing.
 */
class ChatStatsWidget extends StatsOverviewWidget
{
    use ReadsChatStatistics;

    /**
     * The window every figure but the live queue and the month's spend is read
     * over. Thirty days rather than "this month", so the number does not
     * collapse to nothing on the first of every month and frighten whoever
     * opens the panel that morning.
     */
    protected const int WINDOW_DAYS = 30;

    /**
     * A method rather than the `$heading` property, and every widget here does
     * the same.
     *
     * A property is evaluated where it is written, which is before a request
     * has a locale — so `__()` in one would be resolved once, at class load,
     * against whatever the configuration said. The result reads perfectly on
     * the developer's machine and is the wrong language on an installation
     * that picks one per request.
     */
    public function getHeading(): ?string
    {
        return __('chatbot::panel.widgets.stats.heading');
    }

    /**
     * Every number on the widget, in one place and held for a few seconds.
     *
     * Public so that a test can read the figures instead of the rendered
     * markup: an assertion against what is on screen passes just as happily
     * when the label is right and the number behind it is wrong.
     *
     * The three money figures are null — not zero — for somebody the host will
     * not show budgets to. Zero is an answer, and it is a false one: it says
     * the bot has cost nothing this month.
     *
     * @return array{
     *     conversations: int,
     *     previous_conversations: int,
     *     runs_finished: int,
     *     runs_handed_off: int,
     *     waiting: int,
     *     waiting_for_me: int,
     *     period: string,
     *     tokens: int|null,
     *     cost_micro: int|null,
     *     calls: int|null,
     * }
     */
    public function counters(): array
    {
        return $this->remember('counters', function (): array {
            $since = $this->since(self::WINDOW_DAYS);
            $userId = Filament::auth()->id();

            $volume = $this->volume($since);
            $runs = $this->runs($since);
            $spend = $this->spend();

            return [
                'conversations' => $volume['current'],
                'previous_conversations' => $volume['previous'],
                'runs_finished' => $runs['finished'],
                'runs_handed_off' => $runs['handed_off'],
                'waiting' => Conversation::query()->open()->unread()->count(),
                'waiting_for_me' => Conversation::query()->waitingFor($userId)->count(),
                ...$spend,
            ];
        });
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counters = $this->counters();

        $stats = [
            $this->conversationsStat($counters),
            $this->handoffStat($counters),
            $this->waitingStat($counters),
        ];

        if ($counters['tokens'] !== null) {
            $stats[] = $this->spendStat($counters);
        }

        return $stats;
    }

    /**
     * Conversations begun in the window and in the window before it, in one
     * pass over one index.
     *
     * Only threads somebody has spoken in are counted. A conversation row is
     * created the moment the chat bubble is opened, and it stays empty when the
     * visitor closes the tab without typing; counting those would report a
     * busy week whenever a page with the widget on it was popular.
     *
     * Two windows in one query rather than two queries, because the second is
     * only ever shown as the trend beside the first and a pair of counts taken
     * a moment apart can straddle midnight.
     *
     * @return array{current: int, previous: int}
     */
    private function volume(CarbonInterface $since): array
    {
        $row = Conversation::query()
            ->whereNotNull('last_message_at')
            ->where('created_at', '>=', $this->since(self::WINDOW_DAYS * 2))
            ->selectRaw('count(*) as both_windows')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as current_window', [$since])
            ->first();

        $current = (int) ($row?->current_window ?? 0);

        return [
            'current' => $current,
            'previous' => (int) ($row?->both_windows ?? 0) - $current,
        ];
    }

    /**
     * How often the bot finished on its own, counted on runs and not on
     * conversations.
     *
     * The conversation row cannot answer this. A conversation the bot ran and
     * closed is set back to `bot_state = off`, which is the same value as one
     * the bot never touched, so "did the bot work here" is unreadable from it —
     * and a bot that finishes well would score as a bot that never ran. A run
     * is written every time the bot enters a scenario and carries how it ended.
     *
     * Every finished run either closed the conversation or called a person:
     * a handoff, a dangling branch, a loop that hit the step limit, a
     * disappeared step and a crash all end with somebody being asked to take
     * over. So "needed a human" is everything that is not `Closed`, and the
     * arithmetic cannot drift as outcomes are added.
     *
     * `whereHas('conversation')` is what keeps rehearsals out. Runs carry no
     * mark of their own: the quality checker drives the engine for real, so its
     * runs are ordinary rows in this table and only the thread behind them is
     * marked as a rehearsal. Asking for the conversation is how that thread's
     * global scope reaches this count. Without it a suite rehearsed nightly
     * would report the bot handing off dozens of times a week to nobody.
     *
     * @return array{finished: int, handed_off: int}
     */
    private function runs(CarbonInterface $since): array
    {
        $row = Run::query()
            ->whereHas('conversation')
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $since)
            ->selectRaw('count(*) as finished')
            ->selectRaw('sum(case when outcome = ? then 1 else 0 end) as unaided', [RunOutcome::Closed->value])
            ->first();

        $finished = (int) ($row?->finished ?? 0);

        return [
            'finished' => $finished,
            'handed_off' => $finished - (int) ($row?->unaided ?? 0),
        ];
    }

    /**
     * This period's model spending, read from the ledger the package already
     * keeps.
     *
     * The month comes from `BudgetGuard::period()` and the token total from
     * `BudgetPeriod::totalTokens()`, neither of them re-derived here. Both are
     * decisions with reasons behind them — the month is the application's own
     * calendar month and not UTC's, and the total is input plus output because
     * that is what a provider bills — and a widget that formed its own opinion
     * would sooner or later disagree with the number that actually stops the
     * bot spending, with nothing on screen to say which is right.
     *
     * Summed across bots in PHP over a handful of rows rather than in SQL, so
     * that `totalTokens()` stays the one definition of what counts as a token.
     *
     * @return array{period: string, tokens: int|null, cost_micro: int|null, calls: int|null}
     */
    private function spend(): array
    {
        $period = app(BudgetGuard::class)->period();

        if (Filament::auth()->user()?->can('viewAny', BudgetPeriod::class) !== true) {
            return ['period' => $period, 'tokens' => null, 'cost_micro' => null, 'calls' => null];
        }

        $rows = BudgetPeriod::query()->where('period', $period)->get();

        return [
            'period' => $period,
            'tokens' => (int) $rows->sum(fn (BudgetPeriod $row): int => $row->totalTokens()),
            'cost_micro' => (int) $rows->sum('cost_micro'),
            'calls' => (int) $rows->sum('calls'),
        ];
    }

    /**
     * @param  array<string, mixed>  $counters
     */
    private function conversationsStat(array $counters): Stat
    {
        $current = (int) $counters['conversations'];
        $previous = (int) $counters['previous_conversations'];
        $change = $current - $previous;

        $stat = Stat::make(__('chatbot::panel.widgets.stats.conversations'), number_format($current))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight);

        if ($previous === 0) {
            return $stat->description(__('chatbot::panel.widgets.stats.conversations_first', [
                'days' => self::WINDOW_DAYS,
            ]));
        }

        return $stat
            ->description(__('chatbot::panel.widgets.stats.conversations_change', [
                'change' => sprintf('%+d', $change),
                'days' => self::WINDOW_DAYS,
            ]))
            ->descriptionIcon($change < 0 ? Heroicon::ArrowTrendingDown : Heroicon::ArrowTrendingUp)
            ->descriptionColor($change < 0 ? 'danger' : 'success');
    }

    /**
     * The share the bot could not finish, stated as a share and not as a count.
     *
     * Ten handoffs is a good week on a busy desk and a broken bot on a quiet
     * one, and the tile has no room to say which — so it shows the proportion
     * and puts both counts underneath.
     *
     * @param  array<string, mixed>  $counters
     */
    private function handoffStat(array $counters): Stat
    {
        $finished = (int) $counters['runs_finished'];
        $handedOff = (int) $counters['runs_handed_off'];

        if ($finished === 0) {
            return Stat::make(__('chatbot::panel.widgets.stats.handed_off'), '—')
                ->description(__('chatbot::panel.widgets.stats.handed_off_none', [
                    'days' => self::WINDOW_DAYS,
                ]))
                ->icon(Heroicon::OutlinedUserGroup);
        }

        $share = (int) round($handedOff / $finished * 100);

        return Stat::make(__('chatbot::panel.widgets.stats.handed_off'), $share.'%')
            ->description(__('chatbot::panel.widgets.stats.handed_off_share', [
                'handed' => $handedOff,
                'finished' => $finished,
            ]))
            ->icon(Heroicon::OutlinedUserGroup)
            ->color($share >= 50 ? 'warning' : 'success');
    }

    /**
     * The queue as it stands, with the viewer's own part of it underneath.
     *
     * The headline is the whole desk, because "is anybody waiting" is a
     * question about the agency and not about the reader. The description
     * narrows to them, by the same rule the unread badge uses — a colleague's
     * conversation is not this person's work.
     *
     * @param  array<string, mixed>  $counters
     */
    private function waitingStat(array $counters): Stat
    {
        $waiting = (int) $counters['waiting'];
        $mine = (int) $counters['waiting_for_me'];

        return Stat::make(__('chatbot::panel.widgets.stats.waiting'), number_format($waiting))
            ->description($waiting === 0
                ? __('chatbot::panel.widgets.stats.waiting_none')
                : __('chatbot::panel.widgets.stats.waiting_mine', ['count' => $mine]))
            ->icon(Heroicon::OutlinedClock)
            ->color($waiting === 0 ? 'success' : 'warning');
    }

    /**
     * Tokens as the headline and money underneath, deliberately that way round.
     *
     * Tokens are the figure the package knows; a price per token belongs to the
     * provider and changes without this package hearing about it. Cost is
     * recorded only when whatever calls the model reports one, so a zero there
     * means "nobody told us", and saying so is better than printing 0.00 next
     * to a month of real spending.
     *
     * @param  array<string, mixed>  $counters
     */
    private function spendStat(array $counters): Stat
    {
        $cost = (int) $counters['cost_micro'];
        $calls = (int) $counters['calls'];

        $description = $cost > 0
            ? __('chatbot::panel.widgets.stats.tokens_spent', [
                'cost' => number_format($cost / 1000000, 2),
                'calls' => number_format($calls),
            ])
            : __('chatbot::panel.widgets.stats.tokens_no_cost', ['calls' => number_format($calls)]);

        return Stat::make(__('chatbot::panel.widgets.stats.tokens'), number_format((int) $counters['tokens']))
            ->description($description)
            ->icon(Heroicon::OutlinedBanknotes);
    }
}
