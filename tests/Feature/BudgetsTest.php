<?php

declare(strict_types=1);

use MB\FilamentChatbot\Budgets\BudgetGuard;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\BudgetPeriod;

beforeEach(function (): void {
    config()->set('chatbot.budgets.enabled', true);
    config()->set('chatbot.budgets.monthly_tokens', null);
    config()->set('chatbot.budgets.monthly_cost', null);

    $this->guard = app(BudgetGuard::class);
});

/**
 * Two conversations arriving together must not both pass a check against the
 * same remaining balance. Reserved and not yet settled is the state the naive
 * implementation cannot see, so both calls are made before either is closed —
 * exactly the window two web workers occupy.
 */
it('refuses a second reservation while the first is still outstanding', function (): void {
    $bot = Bot::factory()->create(['monthly_token_budget' => 1_000]);

    $first = $this->guard->reserve($bot, 600);
    $second = $this->guard->reserve($bot, 600);

    expect($first->isAllowed())->toBeTrue()
        ->and($second->isDenied())->toBeTrue()
        ->and($second->reason)->toContain('1000');
});

/**
 * Without a budget nothing is refused, but the month still has to know what it
 * cost. An installation that only counted once a limit existed would have
 * nothing to answer the first question anybody asks after switching AI on.
 */
it('records spending when no budget is set at all', function (): void {
    $bot = Bot::factory()->create();

    $reservation = $this->guard->reserve($bot, 500);
    $this->guard->settle($reservation, 420, 130, 2_500);

    expect($reservation->isAllowed())->toBeTrue();

    $period = $this->guard->usage($bot);

    expect($period->input_tokens)->toBe(420)
        ->and($period->output_tokens)->toBe(130)
        ->and($period->cost_micro)->toBe(2_500)
        ->and($period->calls)->toBe(1);
});

it('records spending for calls that belong to no bot', function (): void {
    $reservation = $this->guard->reserve(null, 100);
    $this->guard->settle($reservation, 90, 40, 700);

    $period = $this->guard->usage(null);

    expect($period->bot_id)->toBeNull()
        ->and($period->totalTokens())->toBe(130)
        ->and(BudgetPeriod::query()->count())->toBe(1);
});

it('answers with a refusal rather than throwing when the budget is spent', function (): void {
    $bot = Bot::factory()->create(['monthly_token_budget' => 100]);

    $reservation = $this->guard->reserve($bot, 500);

    expect($reservation->isDenied())->toBeTrue()
        ->and($reservation->reason)->toBeString()
        ->and($reservation->periodId)->toBeNull();
});

it('does not record usage for a reservation that was refused', function (): void {
    $bot = Bot::factory()->create(['monthly_token_budget' => 10]);

    $reservation = $this->guard->reserve($bot, 500);
    $this->guard->settle($reservation, 400, 200, 9_000);

    $period = $this->guard->usage($bot);

    expect($period->totalTokens())->toBe(0)
        ->and($period->cost_micro)->toBe(0)
        ->and($period->calls)->toBe(0);
});

/**
 * The estimate has to come back out, or a run of provider errors exhausts a
 * month without a single answer having been generated.
 */
it('gives the estimate back when the call never happened', function (): void {
    $bot = Bot::factory()->create(['monthly_token_budget' => 1_000]);

    $reservation = $this->guard->reserve($bot, 900);
    $this->guard->release($reservation);

    expect($this->guard->usage($bot)->totalTokens())->toBe(0)
        ->and($this->guard->usage($bot)->calls)->toBe(0)
        ->and($this->guard->reserve($bot, 900)->isAllowed())->toBeTrue();
});

it('replaces the estimate with the real figures instead of adding to it', function (): void {
    $bot = Bot::factory()->create();

    $reservation = $this->guard->reserve($bot, 800);
    $this->guard->settle($reservation, 100, 50, 10);

    expect($this->guard->usage($bot)->input_tokens)->toBe(100);
});

it('stops the month once the cost ceiling is reached', function (): void {
    $bot = Bot::factory()->create(['monthly_cost_budget' => 5_000]);

    $first = $this->guard->reserve($bot, 100);
    $this->guard->settle($first, 100, 100, 5_000);

    expect($this->guard->reserve($bot, 100)->isDenied())->toBeTrue();
});

it('keeps each bot inside its own budget', function (): void {
    $spender = Bot::factory()->create(['monthly_token_budget' => 100]);
    $other = Bot::factory()->create(['monthly_token_budget' => 100]);

    $this->guard->reserve($spender, 100);

    expect($this->guard->reserve($spender, 100)->isDenied())->toBeTrue()
        ->and($this->guard->reserve($other, 100)->isAllowed())->toBeTrue();
});

/**
 * A budget of zero is a deliberate stop, not an unset field. Read as "fall back
 * to the installation default" it would turn a switched-off bot back on.
 */
it('treats a zero budget as zero rather than as unset', function (): void {
    config()->set('chatbot.budgets.monthly_tokens', 10_000);

    $bot = Bot::factory()->create(['monthly_token_budget' => 0]);

    expect($this->guard->reserve($bot, 1)->isDenied())->toBeTrue();
});

it('falls back to the installation budget when the bot sets none', function (): void {
    config()->set('chatbot.budgets.monthly_tokens', 50);

    $bot = Bot::factory()->create();

    expect($this->guard->reserve($bot, 40)->isAllowed())->toBeTrue()
        ->and($this->guard->reserve($bot, 40)->isDenied())->toBeTrue();
});

it('stops refusing when budgets are switched off but keeps counting', function (): void {
    config()->set('chatbot.budgets.enabled', false);

    $bot = Bot::factory()->create(['monthly_token_budget' => 10]);

    $reservation = $this->guard->reserve($bot, 5_000);
    $this->guard->settle($reservation, 5_000, 1_000, 42);

    expect($reservation->isAllowed())->toBeTrue()
        ->and($this->guard->usage($bot)->totalTokens())->toBe(6_000);
});

it('counts each month separately', function (): void {
    $bot = Bot::factory()->create(['monthly_token_budget' => 100]);

    $this->travelTo(now()->startOfMonth()->addDays(2));
    $this->guard->reserve($bot, 100);

    expect($this->guard->reserve($bot, 100)->isDenied())->toBeTrue();

    $this->travelTo(now()->addMonth());

    expect($this->guard->reserve($bot, 100)->isAllowed())->toBeTrue()
        ->and(BudgetPeriod::query()->forBot($bot)->count())->toBe(2);
});
