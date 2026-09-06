<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use MB\FilamentChatbot\Budgets\BudgetGuard;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Filament\Widgets\ChatStatsWidget;
use MB\FilamentChatbot\Filament\Widgets\ConversationsChartWidget;
use MB\FilamentChatbot\Filament\Widgets\ScenarioOutcomesWidget;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Run;
use Workbench\App\Models\User;

/**
 * Draws all three widgets, the way a dashboard carrying them does.
 */
function renderChatWidgets(): void
{
    Livewire::test(ChatStatsWidget::class)->assertOk();
    Livewire::test(ConversationsChartWidget::class)->assertOk();
    Livewire::test(ScenarioOutcomesWidget::class)->assertOk();
}

/**
 * How many queries the three widgets cost with this much history behind them.
 *
 * Rendered once before counting, because a cold render pays for the session,
 * the panel's own lookups and Livewire's boot, and those would drown the
 * difference this is looking for.
 *
 * The cache is emptied between the warm render and the counted one. Without
 * that the counted render answers out of cache, costs nothing at all, and the
 * comparison below passes as zero against zero — green against a widget that
 * fetches a row per conversation.
 */
function chatWidgetQueries(int $conversations): int
{
    Conversation::factory()->count($conversations)->unread()->create(['last_message_at' => now()])
        ->each(function (Conversation $conversation): void {
            Message::factory()->for($conversation)->create();
            Run::factory()->for($conversation)->endedWith(RunOutcome::HandedOff)->create();
        });

    renderChatWidgets();

    Cache::flush();

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    renderChatWidgets();

    return $count;
}

/**
 * Rehearsal threads — the quality runner's and the panel's sandbox — drive the
 * engine for real: they write conversations, messages and runs like anybody
 * else. Counted as work, a suite rehearsed nightly inflates every figure on the
 * dashboard, and the inflation is invisible because the numbers still look
 * plausible. Runs are the exposed side of this: nothing marks a run as a
 * rehearsal, only the conversation behind it.
 */
it('leaves rehearsals out of the counters', function (): void {
    signIn();

    $real = Conversation::factory()->create(['last_message_at' => now()]);
    Message::factory()->for($real)->create();
    Run::factory()->for($real)->endedWith(RunOutcome::HandedOff)->create(['scenario_slug' => 'greeting']);

    foreach (Conversation::rehearsalMetaKeys() as $key) {
        $rehearsal = Conversation::factory()->create([
            'last_message_at' => now(),
            'meta' => [$key => 7],
        ]);

        Message::factory()->for($rehearsal)->create();
        Run::factory()->for($rehearsal)->endedWith(RunOutcome::HandedOff)->create(['scenario_slug' => 'rehearsed']);
    }

    $counters = Livewire::test(ChatStatsWidget::class)->instance()->counters();
    $outcomes = Livewire::test(ScenarioOutcomesWidget::class)->instance()->outcomes();
    $daily = Livewire::test(ConversationsChartWidget::class)->instance()->daily();

    expect($counters['conversations'])->toBe(1)
        ->and($counters['runs_finished'])->toBe(1)
        ->and($counters['runs_handed_off'])->toBe(1)
        ->and(array_keys($outcomes))->toBe(['greeting'])
        ->and(array_sum($daily['channels']['site']))->toBe(1);
});

/**
 * The month's spending is a number the package already keeps, and the guard is
 * the only thing allowed to decide what it means: which month it is, and that a
 * token is an input token plus an output one. A widget that added the columns
 * up its own way would sooner or later disagree with the figure that actually
 * stops the bot spending, and nothing on screen would say which of the two is
 * lying.
 */
it('reads the period spend from the ledger the package keeps', function (): void {
    signIn();

    $guard = app(BudgetGuard::class);

    $bot = Bot::factory()->create();
    $another = Bot::factory()->create();

    $guard->settle($guard->reserve($bot, 100), 120, 80, 4000000);
    $guard->settle($guard->reserve($another, 50), 30, 20, 1000000);

    $counters = Livewire::test(ChatStatsWidget::class)->instance()->counters();

    expect($counters['tokens'])
        ->toBe($guard->usage($bot)->totalTokens() + $guard->usage($another)->totalTokens())
        ->and($counters['tokens'])->toBe(250)
        ->and($counters['cost_micro'])->toBe(5000000)
        ->and($counters['calls'])->toBe(2)
        ->and($counters['period'])->toBe($guard->period());
});

/**
 * The queue is counted per person and held in cache for a few seconds. With a
 * key that does not name the viewer, the first person to open the dashboard
 * hands their own share of the queue — including conversations assigned to them
 * personally — to everybody else for the life of the entry.
 */
it('counts the waiting work for the person looking at it', function (): void {
    $mine = User::factory()->create();
    $colleague = User::factory()->create();

    Conversation::factory()->unread()->create([
        'assigned_to_id' => $mine->getKey(),
        'last_message_at' => now(),
    ]);

    signIn($mine);
    $forMe = Livewire::test(ChatStatsWidget::class)->instance()->counters();

    signIn($colleague);
    $forThem = Livewire::test(ChatStatsWidget::class)->instance()->counters();

    expect($forMe['waiting_for_me'])->toBe(1)
        ->and($forThem['waiting_for_me'])->toBe(0)
        ->and($forThem['waiting'])->toBe(1);
});

/**
 * Money nobody is allowed to see must be absent, not zero.
 *
 * Zero is an answer, and a false one: it says the bot has cost nothing this
 * month. A host that has taken budgets away from support staff would be showing
 * them a spend figure they cannot check and cannot question.
 */
it('keeps the period spend from somebody the host will not show budgets to', function (): void {
    signIn();

    app(BudgetGuard::class)->settle(app(BudgetGuard::class)->reserve(null, 10), 10, 10, 1000);

    expect(Livewire::test(ChatStatsWidget::class)->instance()->counters()['tokens'])->toBe(20);

    Gate::define('chatbot.view.budgets', fn (): bool => false);
    Cache::flush();

    expect(Livewire::test(ChatStatsWidget::class)->instance()->counters()['tokens'])->toBeNull();
});

/**
 * A widget is a screen like any other, and the panel decides whether to draw it
 * by asking the class — never the record. Answered the wrong way round, the
 * counts of somebody else's customers appear on the dashboard of a person the
 * host has deliberately kept out of the chat.
 *
 * The user is signed in on the panel's guard alone, which is the shape of a
 * real request. A widget asking `auth()` instead of `Filament::auth()` answers
 * false here while somebody is plainly looking at the panel — and the symptom
 * is a dashboard that is simply empty, with nothing anywhere naming the cause.
 */
it('shows the widgets to an operator and nobody else', function (): void {
    signIn();

    expect(ChatStatsWidget::canView())->toBeTrue()
        ->and(ConversationsChartWidget::canView())->toBeTrue()
        ->and(ScenarioOutcomesWidget::canView())->toBeTrue();

    Gate::define('chatbot.view.conversations', fn (): bool => false);

    expect(ChatStatsWidget::canView())->toBeFalse()
        ->and(ConversationsChartWidget::canView())->toBeFalse()
        ->and(ScenarioOutcomesWidget::canView())->toBeFalse();
});

/**
 * A day nothing happened on has to be in the chart as a zero.
 *
 * Left out, Chart.js joins the day before to the day after with a straight
 * line, and a channel that stopped delivering for three days is drawn as one
 * that was merely quiet — which is the one thing this chart exists to make
 * visible.
 */
it('keeps the silent days and the channels apart', function (): void {
    signIn();

    Conversation::factory()->count(2)->create(['channel' => 'site', 'last_message_at' => now()]);
    Conversation::factory()->create([
        'channel' => 'telegram',
        'last_message_at' => now(),
        'created_at' => now()->subDays(3),
    ]);

    $daily = Livewire::test(ConversationsChartWidget::class)->instance()->daily();

    expect($daily['days'])->toHaveCount(14)
        ->and($daily['channels']['site'])->toHaveCount(14)
        ->and(end($daily['channels']['site']))->toBe(2)
        ->and($daily['channels']['telegram'][10])->toBe(1)
        ->and(end($daily['channels']['telegram']))->toBe(0);
});

/**
 * The property, not a ceiling: twenty conversations must cost what two cost.
 *
 * A ceiling on the number of queries catches a change of decoration; this
 * catches the thing that actually breaks — an aggregate replaced by a loop, or
 * a widget that fetches conversations to count them. The dashboard is drawn on
 * every load of the panel by every member of staff, so a widget whose cost
 * follows the size of the chat makes the whole panel slower the better the chat
 * does, and nothing on screen looks any different.
 */
it('draws twenty conversations for the price of two', function (): void {
    signIn();

    $few = chatWidgetQueries(2);
    $many = chatWidgetQueries(20);

    expect($many)->toBe($few)
        ->and($few)->toBeGreaterThan(0);
});
