<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Pages\ManageQualityTests;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Pages\ViewQualityTest;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * The screen a saved check lives on.
 *
 * The runner and the console command came first, and for a while that was all
 * there was: a check could be written by hand into the database and replayed by
 * `chatbot:quality`, which is another way of saying it belonged to whoever had a
 * terminal. The people who know what the bot must never say are the ones who
 * write its wording, and they do not have one.
 *
 * What is tested here is what breaks silently. Not the layout — three of these
 * assertions are about a badge, and every one of them guards a sentence that
 * would otherwise be quietly wrong about whether the bot works.
 */
beforeEach(function (): void {
    signIn();
});

function checkableScenario(string $slug = 'hours'): Scenario
{
    $scenario = Scenario::factory()->create(['bot_id' => null, 'slug' => $slug, 'is_active' => true]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'reply',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'reply',
        'type' => 'say',
        'body' => 'We are open until six.',
    ]);

    return $scenario;
}

/**
 * The button the screen exists for. Without it this is a list of rows somebody
 * still has to open a terminal to act on.
 */
it('runs a check from the list and records what happened', function (): void {
    $scenario = checkableScenario();

    $check = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying(['when do you close?'], ['says' => ['open until six']])
        ->create();

    Livewire::test(ManageQualityTests::class)
        ->callAction(TestAction::make('run')->table($check));

    expect($check->refresh()->last_outcome)->toBe(QualityOutcome::Passed)
        ->and($check->last_run_at)->not->toBeNull()
        ->and($check->last_transcript)->not->toBeEmpty();
});

/**
 * A failed run has to leave behind the three things a person needs to act, and
 * the third one was being computed and thrown away: the nodes the conversation
 * actually went through. Reconstructing that by eye from the transcript is the
 * work the check was supposed to save.
 */
it('keeps the transcript and the nodes it walked, not just the verdict', function (): void {
    $scenario = checkableScenario();

    $check = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying(['when do you close?'], ['says' => ['open until midnight']])
        ->create();

    Livewire::test(ManageQualityTests::class)
        ->callAction(TestAction::make('run')->table($check));

    expect($check->refresh()->last_outcome)->toBe(QualityOutcome::Failed)
        ->and($check->last_visited)->toContain('reply')
        ->and($check->last_mismatches)->not->toBeEmpty();
});

/**
 * A green tick against a scenario redrawn since is worse than no tick: it is
 * read as "this still works" by the very person who has just changed the thing
 * it was checking.
 */
it('calls a passed check stale once its scenario has been edited', function (): void {
    $scenario = checkableScenario();

    $check = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying(['when do you close?'], ['says' => ['open until six']])
        ->create();

    Livewire::test(ManageQualityTests::class)
        ->callAction(TestAction::make('run')->table($check));

    expect($check->refresh()->last_outcome)->toBe(QualityOutcome::Passed);

    /*
     * The column's own state, not the rendered page. "Needs a rerun" is also
     * one of the options in the verdict filter, so it is on the page either
     * way — an `assertDontSee` here would fail against perfectly correct code,
     * and the matching `assertSee` would have passed against broken code for
     * the same reason.
     */
    Livewire::test(ManageQualityTests::class)
        ->assertTableColumnStateSet('outcome', __('chatbot::panel.enums.quality_outcome.passed'), $check);

    $scenario->step('reply')->forceFill(['body' => 'We are open until eight.'])->save();

    Livewire::test(ManageQualityTests::class)
        ->assertTableColumnStateSet('outcome', __('chatbot::panel.quality.verdicts.stale'), $check);
});

/**
 * "Never run" is not "passing", and an empty outcome column would read as the
 * latter to anybody scanning for red.
 */
it('says plainly that a check has never been run', function (): void {
    $check = QualityTest::factory()->create();

    Livewire::test(ManageQualityTests::class)
        ->assertTableColumnStateSet('outcome', __('chatbot::panel.quality.verdicts.never'), $check);
});

/**
 * An empty scenario key means two different things, and only one of them is
 * fine. A deleted scenario leaves the slug behind, and without saying so the
 * check silently becomes a check of the whole bot — passing, while guarding
 * something nobody asked it to guard.
 */
it('tells an orphaned check apart from one that checks the whole bot', function (): void {
    $orphan = QualityTest::factory()->forScenario(null, 'deleted-scenario')->create(['name' => 'Orphan']);
    $wholeBot = QualityTest::factory()->forScenario(null, null)->create(['name' => 'Whole bot']);

    Livewire::test(ManageQualityTests::class)
        ->assertTableColumnStateSet(
            'scenario',
            __('chatbot::panel.quality.table.orphaned', ['slug' => 'deleted-scenario']),
            $orphan,
        )
        ->assertTableColumnStateSet(
            'scenario',
            __('chatbot::panel.quality.table.whole_bot'),
            $wholeBot,
        );
});

/**
 * A copy that inherited the original's marks would put a green tick under a
 * check nobody has ever executed.
 */
it('gives a copied check no verdict of its own', function (): void {
    $check = QualityTest::factory()->ranWith(QualityOutcome::Passed)->create(['name' => 'Original']);

    Livewire::test(ManageQualityTests::class)
        ->callAction(TestAction::make('replicate')->table($check));

    $copy = QualityTest::query()->where('name', '!=', 'Original')->sole();

    expect($copy->last_outcome)->toBeNull()
        ->and($copy->last_run_at)->toBeNull();
});

/**
 * The whole-suite button skips what was switched off. A run that quietly
 * included them would report failures for checks somebody had already decided
 * not to keep.
 */
it('leaves switched-off checks out of a whole-suite run', function (): void {
    $scenario = checkableScenario();

    $active = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying(['when do you close?'], ['says' => ['open until six']])
        ->create();

    $parked = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->inactive()
        ->saying(['when do you close?'], ['says' => ['open until six']])
        ->create();

    Livewire::test(ManageQualityTests::class)->callAction('runAll');

    expect($active->refresh()->last_run_at)->not->toBeNull()
        ->and($parked->refresh()->last_run_at)->toBeNull();
});

/**
 * The detail screen is the one anybody opens on purpose, and it opens against a
 * check that has never run — which is the state a check is in the moment
 * somebody follows a link to it from the readiness page.
 */
it('opens the detail screen for a check that has never run', function (): void {
    $check = QualityTest::factory()->create();

    Livewire::test(ViewQualityTest::class, ['record' => $check->getKey()])
        ->assertOk()
        ->assertSee(__('chatbot::panel.quality.detail.never_run'));
});
