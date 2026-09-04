<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * The four things about scenarios and steps that break without saying so.
 *
 * A lookup that starts querying per step still returns the right answer, an
 * unknown step kind only throws on the installation that registered it, an
 * option missing its destination looks fine in the editor, and a duplicate key
 * makes an arrow ambiguous rather than absent. None of these show up as a
 * failure anyone would notice while looking at the screen.
 */

/**
 * Counts the queries a full walk of a scenario costs.
 *
 * The measurement is repeated once before it is taken, because the first pass
 * through any of this pays for schema reads and connection setup that have
 * nothing to do with the code under test — and a warm-up left out is how a
 * query-count test ends up asserting a number nobody understands.
 *
 * @return array{queries: int, found: int}
 */
function walkScenarioOfSize(int $stepCount): array
{
    $scenario = Scenario::factory()->create();

    $keys = [];

    for ($index = 0; $index < $stepCount; $index++) {
        $key = "node-{$scenario->getKey()}-{$index}";

        Step::factory()->for($scenario)->create([
            'key' => $key,
            'sort' => $index,
        ]);

        $keys[] = $key;
    }

    $walk = function () use ($scenario, $keys): int {
        $loaded = Scenario::query()->with('steps')->findOrFail($scenario->getKey());

        $found = 0;

        foreach ($keys as $key) {
            if ($loaded->step($key) !== null) {
                $found++;
            }
        }

        return $found;
    };

    $walk();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $found = $walk();

    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    return ['queries' => $queries, 'found' => $found];
}

it('finds a step in the loaded collection instead of querying for each one', function (): void {
    $long = walkScenarioOfSize(5);
    $short = walkScenarioOfSize(1);

    expect($long['found'])->toBe(5)
        ->and($short['found'])->toBe(1)
        ->and($long['queries'])->toBe($short['queries']);
});

it('falls back to a single query when the steps are not loaded', function (): void {
    $scenario = Scenario::factory()->create();

    Step::factory()->for($scenario)->create(['key' => 'ask-budget']);

    $unloaded = Scenario::query()->findOrFail($scenario->getKey());

    expect($unloaded->relationLoaded('steps'))->toBeFalse()
        ->and($unloaded->step('ask-budget')?->key)->toBe('ask-budget')
        ->and($unloaded->step('never-drawn'))->toBeNull();
});

it('stores and reads back a step kind it has never heard of', function (): void {
    $scenario = Scenario::factory()->create();

    $step = Step::factory()->for($scenario)->create([
        'key' => 'pick-a-booking',
        'type' => 'host.crm.pick-booking',
    ]);

    $fresh = Step::query()->findOrFail($step->getKey());

    expect($fresh->type)->toBe('host.crm.pick-booking');

    $fresh->update(['body' => 'Which booking do you mean?']);

    expect(Scenario::query()->with('steps')->findOrFail($scenario->getKey())->steps)
        ->toHaveCount(1);
});

it('drops options with no label and options with no destination, keeping the rest in order', function (): void {
    $step = Step::factory()->create([
        'type' => 'ask',
        'options' => [
            ['label' => 'Book a trip', 'next' => 'booking'],
            ['label' => '', 'next' => 'orphan-label'],
            ['label' => 'Half-drawn', 'next' => ''],
            ['label' => 'Talk to a human', 'next' => 'handoff'],
            'not-an-option',
        ],
    ]);

    expect(array_column($step->choices(), 'label'))
        ->toBe(['Book a trip', 'Talk to a human'])
        ->and(array_column($step->choices(), 'next'))
        ->toBe(['booking', 'handoff']);
});

it('refuses a second step with the same key inside one scenario', function (): void {
    $scenario = Scenario::factory()->create();

    Step::factory()->for($scenario)->create(['key' => 'greeting']);

    expect(fn () => Step::factory()->for($scenario)->create(['key' => 'greeting']))
        ->toThrow(QueryException::class);
});

it('allows the same step key in a different scenario', function (): void {
    $first = Scenario::factory()->create();
    $second = Scenario::factory()->create();

    Step::factory()->for($first)->create(['key' => 'greeting']);
    Step::factory()->for($second)->create(['key' => 'greeting']);

    expect(Step::query()->where('key', 'greeting')->count())->toBe(2);
});
