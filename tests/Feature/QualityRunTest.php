<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use MB\FilamentChatbot\Console\Commands\RunQualityChecksCommand;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Quality\QualityResult;
use MB\FilamentChatbot\Quality\QualityRunner;

/**
 * Builds a scenario from a compact description.
 *
 * A second copy of the engine suite's helper rather than a shared one, and
 * deliberately named differently: Pest loads every test file into one process,
 * and two files declaring the same global function is a fatal error nobody can
 * read from the output.
 *
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, mixed>  $attributes
 */
function rehearsalScenario(array $steps, array $attributes = []): Scenario
{
    $scenario = Scenario::factory()->create($attributes + ['bot_id' => null]);

    foreach ($steps as $index => $step) {
        Step::factory()->create([
            'scenario_id' => $scenario->getKey(),
            'sort' => $index,
        ] + $step);
    }

    return $scenario->load('steps');
}

/**
 * @param  array<string, mixed>  $expectations
 * @param  list<string>  $said
 * @param  array<string, mixed>  $attributes
 */
function checkFor(Scenario $scenario, array $said, array $expectations, array $attributes = []): QualityTest
{
    return QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying($said, $expectations)
        ->create($attributes);
}

function runCheck(QualityTest $check): QualityResult
{
    return app(QualityRunner::class)->run($check);
}

beforeEach(function (): void {
    $this->bot = Bot::factory()->create();

    /*
     * The command is registered by hand because the package's provider registers
     * only the commands it shipped with. Registering it here keeps the console
     * behaviour under test rather than assumed.
     */
    $this->app->make(Kernel::class)->registerCommand($this->app->make(RunQualityChecksCommand::class));
});

/**
 * The distinction the whole outcome enum exists for. Reported as a failure, this
 * reads as a bot that answered wrongly, and whoever opens it goes looking for a
 * bug in a conversation that never happened — while the actual fault, a scenario
 * somebody deleted, is not mentioned anywhere.
 */
it('reports a check whose scenario has been deleted as broken rather than as failed', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ]);

    $check = checkFor($scenario, ['hello'], ['says' => 'We open at nine.']);

    $scenario->delete();

    $result = runCheck($check->refresh());

    expect($result->outcome())->toBe(QualityOutcome::Broken)
        ->and($result->isPassing())->toBeFalse()
        ->and($result->reason())->toContain($check->scenario_slug)
        ->and($check->fresh()->last_outcome)->toBe(QualityOutcome::Broken);
});

/**
 * A check that only knows how to look for what must be there proves half of what
 * anybody wants from it. The sentence a bot must never say — a price it may not
 * quote, an address it may not give out — fails silently: the required phrase is
 * present, the forbidden one is present too, and the check is green.
 */
it('fails a check when the bot says something it was told never to say', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine. Refunds are guaranteed.'],
    ]);

    $forbidden = checkFor($scenario, ['hello'], [
        'says' => 'We open at nine.',
        'never_says' => 'guaranteed',
    ]);

    $result = runCheck($forbidden);

    expect($result->outcome())->toBe(QualityOutcome::Failed)
        ->and(array_column($result->mismatches(), 'kind'))->toBe(['never_says']);

    $onlyRequired = checkFor($scenario, ['hello'], ['says' => 'We open at nine.']);

    expect(runCheck($onlyRequired)->outcome())->toBe(QualityOutcome::Passed);
});

/**
 * Handing a visitor to a person and saying goodbye are the two endings that look
 * identical in a transcript and mean opposite things about the bot. A scenario
 * that hands off where it should close costs an operator every conversation.
 */
it('tells a conversation handed to a person from one the bot closed', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'human', 'config' => ['trigger' => 'fallback']],
        ['key' => 'human', 'type' => 'handoff', 'body' => 'One moment.'],
    ]);

    $expectingClose = checkFor($scenario, ['hello'], ['ends_with' => 'closed']);
    $result = runCheck($expectingClose);

    expect($result->outcome())->toBe(QualityOutcome::Failed)
        ->and($result->mismatches()[0])->toMatchArray([
            'kind' => 'ends_with',
            'expected' => 'closed',
            'actual' => 'handed_off',
        ]);

    $expectingHandoff = checkFor($scenario, ['hello'], ['ends_with' => 'handed_off']);

    expect(runCheck($expectingHandoff)->outcome())->toBe(QualityOutcome::Passed);
});

/**
 * A branch taken wrongly says something plausible on the way. Only the path
 * proves which arrow was followed, and it is the one thing a transcript cannot
 * show.
 */
it('catches a scenario that answered from the wrong branch', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'pick', 'config' => ['trigger' => 'fallback']],
        ['key' => 'pick', 'type' => 'ask', 'body' => 'Sales or support?', 'options' => [
            ['label' => 'Sales', 'next' => 'sales'],
            ['label' => 'Support', 'next' => 'support'],
        ]],
        ['key' => 'sales', 'type' => 'say', 'body' => 'Someone will be with you.'],
        ['key' => 'support', 'type' => 'say', 'body' => 'Someone will be with you.'],
    ]);

    $wrongBranch = checkFor($scenario, ['hello', 'sales'], ['visits' => ['support']]);
    $result = runCheck($wrongBranch);

    expect($result->outcome())->toBe(QualityOutcome::Failed)
        ->and($result->mismatches()[0]['kind'])->toBe('visits')
        ->and($result->visited())->toContain('sales');

    $rightBranch = checkFor($scenario, ['hello', 'sales'], ['visits' => ['pick', 'sales']]);

    expect(runCheck($rightBranch)->outcome())->toBe(QualityOutcome::Passed);
});

/**
 * The rehearsal thread must not reach the desk. An operator who opens it answers
 * a customer who never wrote, and there is nothing on the row to tell them so.
 */
it('keeps the rehearsal conversation out of the ordinary selection', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ]);

    $real = Conversation::factory()->create(['channel' => 'site']);

    runCheck(checkFor($scenario, ['hello'], ['says' => 'We open at nine.']));

    expect(Conversation::query()->pluck('id')->all())->toBe([$real->getKey()])
        ->and(Conversation::query()->open()->count())->toBe(1)
        ->and(Conversation::query()->withoutGlobalScope(Conversation::REAL_SCOPE)->count())->toBe(2);
});

/**
 * The exit code is the only thing a pipeline reads. A pipeline that falls over
 * because somebody saved a check about a wording still being argued over is a
 * pipeline that gets the whole step removed from it.
 */
it('fails the run for a required check and lets an optional one through', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ]);

    $optional = checkFor($scenario, ['hello'], ['says' => 'We open at ten.'], ['name' => 'Optional wording']);

    $this->artisan('chatbot:quality')->assertExitCode(0);

    expect($optional->fresh()->last_outcome)->toBe(QualityOutcome::Failed);

    checkFor($scenario, ['hello'], ['says' => 'We never say this.'], [
        'name' => 'Required wording',
        'is_required' => true,
    ]);

    $this->artisan('chatbot:quality')->assertExitCode(1);
});

/**
 * A check is a row that carries its latest verdict, not a log. Runs that
 * appended would grow a table forever to answer a question nobody asks, and the
 * rehearsal threads behind them would grow with it.
 */
it('overwrites the last run instead of accumulating rows', function (): void {
    $scenario = rehearsalScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ]);

    $check = checkFor($scenario, ['hello'], ['says' => 'We open at ten.']);

    runCheck($check);

    expect($check->fresh()->last_outcome)->toBe(QualityOutcome::Failed);

    $check->update(['expectations' => ['says' => 'We open at nine.']]);

    $result = runCheck($check->fresh());

    expect($result->outcome())->toBe(QualityOutcome::Passed)
        ->and(QualityTest::query()->count())->toBe(1)
        ->and(Conversation::query()->withoutGlobalScope(Conversation::REAL_SCOPE)->count())->toBe(1)
        ->and($check->fresh()->last_mismatches)->toBeNull()
        ->and($check->fresh()->last_run_at)->not->toBeNull()
        ->and($check->fresh()->last_fingerprint)->not->toBeNull()
        ->and($check->fresh()->last_transcript)->toHaveCount(2);
});

/**
 * A check can rehearse as somebody the application already knows.
 *
 * Without it every branch behind "do we know this person" is unreachable from a
 * saved check, and those are the branches worth checking: everything a bot says
 * about somebody's own order, booking or balance hangs off one. A check written
 * for a known customer ran as a stranger, took the "please sign in" exit, and
 * then passed or failed for reasons that had nothing to do with what it was
 * written to guard.
 *
 * The reference is to a record of the host's, not to data the package makes up.
 * That is what makes the rehearsal worth holding: the scopes, the directory and
 * every data resource behave as they do for that person.
 */
it('rehearses as a customer the host names, and reaches the branch behind that', function (): void {
    $scenario = rehearsalScenario([
        [
            'key' => 'start',
            'type' => 'start',
            'config' => ['trigger' => 'fallback'],
            'next_key' => 'known',
        ],
        [
            'key' => 'known',
            'type' => 'condition',
            'options' => [
                ['label' => 'Known', 'next' => 'welcome', 'when' => ['field' => 'has_contact', 'operator' => 'yes']],
            ],
            'config' => ['else_key' => 'stranger'],
        ],
        ['key' => 'welcome', 'type' => 'say', 'body' => 'Your bookings are below.'],
        ['key' => 'stranger', 'type' => 'say', 'body' => 'Please sign in first.'],
    ], ['slug' => 'known-or-not', 'is_active' => true]);

    $asStranger = QualityTest::factory()->forScenario($scenario->getKey(), $scenario->slug)->create([
        'said' => ['hello'],
        'expectations' => ['visits' => ['stranger']],
    ]);

    $asCustomer = QualityTest::factory()->forScenario($scenario->getKey(), $scenario->slug)->create([
        'said' => ['hello'],
        'context' => ['contact' => ['type' => 'demo-record', 'id' => 7]],
        'expectations' => ['visits' => ['welcome'], 'says' => ['bookings are below']],
    ]);

    $runner = app(QualityRunner::class);

    expect($runner->run($asStranger)->outcome())->toBe(QualityOutcome::Passed)
        ->and($runner->run($asCustomer)->outcome())->toBe(QualityOutcome::Passed);
});
