<?php

declare(strict_types=1);

use Livewire\Livewire;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Bot\Readiness;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Filament\Pages\BotReadinessPage;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * "Why is the bot silent", answered on one screen.
 *
 * Every case here is a way the bot stops working while each individual screen
 * looks correct: a switch turned off somewhere else, a scenario whose diagram
 * cannot be walked, a check that passed against a version nobody runs any more.
 * None of them throws and none appears in a log — the symptom is a conversation
 * that goes to a person, or an answer that never comes.
 *
 * @return array<string, mixed>
 */
function tileOf(string $key): array
{
    foreach (app(Readiness::class)->summary()['tiles'] as $tile) {
        if ($tile['key'] === $key) {
            return $tile;
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function todoTexts(): array
{
    return array_map(
        static fn (array $todo): string => $todo['text'],
        app(Readiness::class)->summary()['todos'],
    );
}

/**
 * The commonest answer of all, and the one that used to be learned last because
 * the switch lives on a screen nobody opens while debugging a scenario.
 */
it('says plainly when nothing would answer a message', function (): void {
    expect(tileOf('enabled')['tone'])->toBe('danger')
        ->and(todoTexts())->toContain(__('chatbot::panel.readiness.todo.no_bot'));

    Bot::factory()->create(['is_active' => true]);

    expect(tileOf('enabled')['tone'])->toBe('success');
});

/**
 * A scenario running as a draft is not a mistake and is worth saying out loud:
 * every edit reaches customers the moment it is saved, which is the state an
 * author is least likely to know they are in.
 */
it('separates what is published from what is running as a draft', function (): void {
    Bot::factory()->create(['is_active' => true]);

    $scenario = Scenario::factory()->create(['bot_id' => null, 'is_active' => true, 'name' => 'Opening hours']);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'say',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'say',
        'type' => 'say',
        'body' => 'Nine to six.',
    ]);

    expect(tileOf('scenarios')['value'])->toBe('1')
        ->and(tileOf('scenarios')['tone'])->toBe('warning')
        ->and(todoTexts())->toContain(
            __('chatbot::panel.readiness.todo.unpublished', ['scenario' => 'Opening hours']),
        );
});

/**
 * A diagram that cannot be walked is counted from the canvas's own verdict, in
 * the canvas's own words. A second implementation of "what is broken" would
 * eventually call a scenario healthy on a page the canvas marks in red.
 */
it('names a scenario whose diagram cannot be walked', function (): void {
    Bot::factory()->create(['is_active' => true]);

    $scenario = Scenario::factory()->create(['bot_id' => null, 'is_active' => true, 'name' => 'Broken']);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'nowhere',
        'config' => ['trigger' => 'fallback'],
    ]);

    expect(tileOf('problems')['tone'])->toBe('danger')
        ->and(tileOf('problems')['value'])->toBe('1');

    $named = array_filter(todoTexts(), static fn (string $text): bool => str_contains($text, 'Broken'));

    expect($named)->not->toBeEmpty();
});

/**
 * A check that passed against a scenario since edited is a green tick for an
 * answer nobody has seen. Left as its stored outcome it reads as proof.
 */
it('tells a check that needs re-running from one that passed', function (): void {
    Bot::factory()->create(['is_active' => true]);

    $scenario = Scenario::factory()->create(['bot_id' => null, 'is_active' => true]);

    QualityTest::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'name' => 'Asks for the order number',
        'last_outcome' => QualityOutcome::Passed,
        'last_fingerprint' => 'a-fingerprint-from-another-life',
    ]);

    expect(tileOf('quality')['tone'])->toBe('warning')
        ->and(todoTexts())->toContain(
            __('chatbot::panel.readiness.todo.check_stale', ['check' => 'Asks for the order number']),
        );
});

/**
 * A handover is the bot working, and counting it as breakage would make a
 * healthy installation look broken every day it fetched somebody.
 */
it('does not count a conversation handed to a person as a broken run', function (): void {
    Bot::factory()->create(['is_active' => true]);

    Run::factory()->count(3)->create(['outcome' => RunOutcome::HandedOff]);

    expect(tileOf('runs')['tone'])->toBe('success')
        ->and(tileOf('runs')['value'])->toBe('3');

    Run::factory()->create(['outcome' => RunOutcome::DeadEnd]);

    expect(tileOf('runs')['tone'])->toBe('danger');
});

/**
 * A topic the base finds and cannot answer is the worst kind: the bot matched
 * the question and then said nothing, which reads to the visitor as a bot that
 * ignored them.
 */
it('names topics the base can find and cannot answer', function (): void {
    Bot::factory()->create(['is_active' => true]);

    KnowledgeEntry::factory()->create(['bot_id' => null, 'is_active' => true, 'answer' => null]);

    expect(tileOf('knowledge')['tone'])->toBe('warning')
        ->and(todoTexts())->toContain(__('chatbot::panel.readiness.todo.knowledge_silent'));
});

/**
 * The list is never empty. Nothing where a list of problems should be reads as
 * "not calculated" rather than "no problems", and the reader would go and check
 * by hand anyway — which is the work this screen exists to save.
 */
it('says so in words when nothing is in the way', function (): void {
    Bot::factory()->create(['is_active' => true]);

    $scenario = Scenario::factory()->create(['bot_id' => null, 'is_active' => true]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'say',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'say',
        'type' => 'say',
        'body' => 'Nine to six.',
    ]);

    app(ScenarioVersions::class)->publish($scenario->fresh('steps'));

    expect(todoTexts())->toContain(__('chatbot::panel.readiness.todo.all_clear'));
});

/**
 * The screen opens for somebody who may look at scenarios, and draws what the
 * summary computed. Asserted through the page as well as the class because the
 * two are joined by a view: a summary nothing renders is a summary nobody reads.
 */
it('draws the summary on a screen', function (): void {
    signIn();

    Bot::factory()->create(['is_active' => true]);

    Livewire::test(BotReadinessPage::class)
        ->assertOk()
        ->assertSee(__('chatbot::panel.readiness.tiles.enabled'))
        ->assertSee(__('chatbot::panel.readiness.answering'))
        ->assertSee(__('chatbot::panel.readiness.what_next'));
});

/**
 * A pluralised line read through `__` comes back with its `{1}…|[2,*]…` rules
 * intact, and the panel prints them at the reader. It goes unnoticed in the
 * language whose plural happens to be the first branch, and the tile that says
 * how many bots answer is exactly such a line.
 */
it('says how many without printing the plural rules at anybody', function (): void {
    Bot::factory()->count(2)->create(['is_active' => true]);
    KnowledgeEntry::factory()->count(3)->create(['bot_id' => null, 'is_active' => true, 'answer' => null]);

    expect(tileOf('enabled')['note'])->not->toContain('|')
        ->and(tileOf('enabled')['note'])->toContain('2')
        ->and(tileOf('knowledge')['note'])->not->toContain('|')
        ->and(tileOf('knowledge')['note'])->toContain('3');
});
