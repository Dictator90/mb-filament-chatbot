<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Graph\GraphConfig;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Demo\DemoData;
use MB\FilamentChatbot\Exceptions\ScenarioNotPublishable;
use MB\FilamentChatbot\Facades\Chatbot;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\ScenarioVersion;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Tests\Support\RecordingWriteStep;

/**
 * A whole diagram in one literal, so that a test says what it draws.
 *
 * @param  list<array<string, mixed>>  $nodes
 * @param  list<array{from: string, slot: int, to: string}>  $edges
 * @return array<string, mixed>
 */
function graphOf(array $nodes, array $edges): array
{
    return [
        'version' => ScenarioGraph::VERSION,
        'nodes' => array_map(static fn (array $node): array => $node + [
            'body' => null,
            'config' => [],
            'options' => [],
            'x' => 0,
            'y' => 0,
        ], $nodes),
        'edges' => $edges,
    ];
}

/**
 * @param  list<array{severity: string, key: string|null, message: string}>  $issues
 * @return list<string|null>
 */
function issueKeys(array $issues, string $severity): array
{
    return array_values(array_map(
        static fn (array $issue): ?string => $issue['key'],
        array_filter($issues, static fn (array $issue): bool => $issue['severity'] === $severity),
    ));
}

/**
 * The graph layer is where a scenario is read for drawing and written back from
 * a canvas, and every fault it can have is a quiet one: a transition lands in
 * the wrong column, a node of an unknown kind disappears during an ordinary
 * save, a release goes out with a write nobody confirms. None of those throw,
 * and none of them are visible on the screen that caused them.
 */
beforeEach(function (): void {
    $this->bot = app(DemoData::class)->seed();
    $this->graph = app(ScenarioGraph::class);
    $this->versions = app(ScenarioVersions::class);
});

/**
 * The one assertion that covers the whole translation.
 *
 * Every demo scenario is read into a diagram, written back, and read again. A
 * transition read from one column and written into another survives a single
 * pass and shows up here on the second, which is exactly how such a bug reaches
 * a customer: the author saves once, sees the picture they drew, and the bot
 * takes a different exit.
 */
it('loses nothing when a scenario is read into a graph and written back', function (): void {
    $scenarios = Scenario::query()->where('bot_id', $this->bot->getKey())->get();

    expect($scenarios)->toHaveCount(6);

    foreach ($scenarios as $scenario) {
        $before = $this->graph->toGraph($scenario);

        $this->graph->apply($scenario, $before);

        expect($this->graph->toGraph($scenario->refresh()))
            ->toEqual($before, "Round trip changed the scenario [{$scenario->slug}].");
    }
});

/**
 * Which handle writes into which column is the one thing the canvas cannot show
 * you. A confirmation's second exit means "declined" and belongs in the
 * second-target setting; a question's last exit means "anything else" and
 * belongs there too, while its first exit is an option. Wire either of them one
 * position out and the diagram still looks right — the bot simply sends the
 * people who said no down the branch written for the people who said yes.
 */
it('puts each handle where the kind of node says it goes', function (): void {
    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'gate', 'type' => 'approval'],
            ['key' => 'menu', 'type' => 'ask', 'options' => [
                ['label' => 'Order'],
                ['label' => 'Problem'],
            ]],
            ['key' => 'yes', 'type' => 'say', 'body' => 'Done.'],
            ['key' => 'no', 'type' => 'say', 'body' => 'Fine.'],
            ['key' => 'orders', 'type' => 'say', 'body' => 'Orders.'],
            ['key' => 'problems', 'type' => 'say', 'body' => 'Problems.'],
            ['key' => 'anything', 'type' => 'say', 'body' => 'Anything else.'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'gate'],
            ['from' => 'gate', 'slot' => 0, 'to' => 'yes'],
            ['from' => 'gate', 'slot' => 1, 'to' => 'no'],
            ['from' => 'yes', 'slot' => 0, 'to' => 'menu'],
            ['from' => 'menu', 'slot' => 0, 'to' => 'orders'],
            ['from' => 'menu', 'slot' => 1, 'to' => 'problems'],
            ['from' => 'menu', 'slot' => 2, 'to' => 'anything'],
        ],
    ));

    $gate = $scenario->steps()->where('key', 'gate')->sole();
    $menu = $scenario->steps()->where('key', 'menu')->sole();

    expect($gate->next_key)->toBe('yes')
        ->and($gate->secondTarget())->toBe('no')
        ->and($menu->choices())->toEqual([
            ['label' => 'Order', 'next' => 'orders', 'words' => [], 'when' => []],
            ['label' => 'Problem', 'next' => 'problems', 'words' => [], 'when' => []],
        ])
        ->and($menu->secondTarget())->toBe('anything')
        ->and($menu->next_key)->toBeNull();
});

/**
 * A node of a kind this installation cannot resolve survives an ordinary save.
 *
 * The scenario may have been drawn while a host package was installed, and the
 * package may be back tomorrow. Dropping the node because nothing answers for
 * its kind deletes an afternoon of somebody's work during a save they made for
 * an unrelated reason, with no undo and no message.
 */
it('keeps a node whose kind nothing provides, and says so', function (): void {
    $scenario = Scenario::factory()->create();

    $graph = graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'legacy', 'type' => 'crm.pick_booking', 'config' => ['resource' => 'bookings'], 'x' => 40, 'y' => 90],
            ['key' => 'bye', 'type' => 'close'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'legacy'],
            ['from' => 'legacy', 'slot' => 0, 'to' => 'bye'],
        ],
    );

    $this->graph->apply($scenario, $graph);
    $this->graph->apply($scenario, $this->graph->toGraph($scenario->refresh()));

    $legacy = $scenario->steps()->where('key', 'legacy')->sole();

    expect($legacy->type)->toBe('crm.pick_booking')
        ->and($legacy->config)->toEqual(['resource' => 'bookings'])
        ->and($legacy->next_key)->toBe('bye')
        ->and($legacy->pos_x)->toBe(40)
        ->and(issueKeys($this->graph->issues($scenario->refresh()), ScenarioGraph::BLOCKING))
        ->toContain('legacy');
});

/**
 * A transition to a key that no longer exists ends the conversation without a
 * word, so it blocks a release. An unfinished branch is the ordinary state of a
 * scenario somebody is working on, so it does not.
 */
it('blocks on a transition to nowhere and only warns about an unfinished branch', function (): void {
    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'hello', 'type' => 'say', 'body' => 'Hi.'],
            ['key' => 'orphan', 'type' => 'say', 'body' => 'Nobody comes here.'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'hello'],
            ['from' => 'hello', 'slot' => 0, 'to' => 'renamed-away'],
        ],
    ));

    $issues = $this->graph->issues($scenario->refresh());

    expect($this->graph->danglingKeys($scenario))->toBe(['renamed-away'])
        ->and($this->graph->unreachableKeys($scenario))->toBe(['orphan'])
        ->and(issueKeys($issues, ScenarioGraph::BLOCKING))->toBe(['hello'])
        ->and(issueKeys($issues, ScenarioGraph::NOTICE))->toBe(['orphan']);
});

/**
 * The steps a diagram no longer mentions are gone after the save, and the order
 * of the rows follows the order of the nodes. A scenario edited by deleting a
 * node and saving must not keep answering through the node that was deleted.
 */
it('removes the nodes a saved diagram no longer contains', function (): void {
    $scenario = Scenario::factory()->create();

    Step::factory()->for($scenario)->start()->create();
    Step::factory()->for($scenario)->create(['key' => 'gone', 'type' => 'say', 'body' => 'Old.']);

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'fresh', 'type' => 'say', 'body' => 'New.'],
        ],
        edges: [['from' => 'start', 'slot' => 0, 'to' => 'fresh']],
    ));

    expect($scenario->steps()->pluck('key')->all())->toBe(['start', 'fresh']);
});

/**
 * The editor checks a diagram while it is being drawn, and that check must not
 * write anything. A validation that saved would publish half-finished work to
 * customers the moment somebody dragged an arrow — and it has to answer the
 * same question as the one that blocks a release, or the canvas shows a clean
 * scenario the publish button refuses.
 */
it('checks an unsaved draft without writing it', function (): void {
    $scenario = Scenario::factory()->create();

    Step::factory()->for($scenario)->start()->create(['next_key' => 'hello']);
    Step::factory()->for($scenario)->create(['key' => 'hello', 'type' => 'say', 'body' => 'Hi.']);

    $issues = $this->graph->issuesForDraft($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'hello', 'type' => 'say', 'body' => 'Hi.'],
            ['key' => 'later', 'type' => 'say', 'body' => 'Later.'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'hello'],
            ['from' => 'hello', 'slot' => 0, 'to' => 'not-drawn-yet'],
        ],
    ));

    expect(issueKeys($issues, ScenarioGraph::BLOCKING))->toBe(['hello'])
        ->and(issueKeys($issues, ScenarioGraph::NOTICE))->toBe(['later'])
        ->and($scenario->steps()->pluck('key')->all())->toBe(['start', 'hello'])
        ->and($this->graph->issues($scenario->refresh()))->toBe([]);
});

/**
 * The editor is handed one box of reference data and asks for nothing else
 * while it draws. Two things fail silently in it: a kind registered by a host
 * that never reaches the palette, so the author cannot place a node that exists;
 * and a value that cannot survive json_encode — a capability's handler closure,
 * say — which empties the whole box on the way to the browser and leaves a
 * canvas with no palette at all and no error anywhere.
 */
it('hands the editor every registered kind in a box that survives the trip', function (): void {
    Chatbot::registerStep(RecordingWriteStep::class);

    $scenario = Scenario::query()->where('bot_id', $this->bot->getKey())->firstOrFail();
    $config = app(GraphConfig::class)->forScenario($scenario);

    $keys = array_column($config['types'], 'value');

    expect($keys)->toContain(RecordingWriteStep::key())
        ->and($keys)->toContain('say', 'ask', 'approval')
        ->and(array_keys($config['defaults']))->toContain(RecordingWriteStep::key())
        ->and(array_column($config['scenarios'], 'slug'))->toContain('welcome')
        ->and($config['canvas']['grid'])->toBeInt();

    $encoded = json_encode($config);

    expect($encoded)->toBeString()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and(json_decode((string) $encoded, true))->toBeArray();
});

/**
 * The one defect that costs money rather than embarrassment.
 *
 * A node that changes the host's records — cancels the booking, awards the
 * points — is only allowed where a person has just said yes. Checked here and
 * not while the conversation runs, because a runtime check happens after the
 * node has been reached: by the time anything could refuse, the write is done
 * and the customer is reading about it.
 */
it('refuses to publish a write a conversation can reach without a confirmation', function (): void {
    Chatbot::registerStep(RecordingWriteStep::class);

    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'gate', 'type' => 'approval', 'body' => 'Cancel it?'],
            ['key' => 'cancel', 'type' => RecordingWriteStep::key()],
            ['key' => 'sorry', 'type' => 'say', 'body' => 'Left as it is.'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'gate'],
            ['from' => 'gate', 'slot' => 0, 'to' => 'cancel'],
            ['from' => 'gate', 'slot' => 1, 'to' => 'sorry'],
        ],
    ));

    expect(issueKeys($this->graph->issues($scenario->refresh()), ScenarioGraph::BLOCKING))->toBe([]);

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'gate', 'type' => 'approval', 'body' => 'Cancel it?'],
            ['key' => 'cancel', 'type' => RecordingWriteStep::key()],
            ['key' => 'sorry', 'type' => 'say', 'body' => 'Left as it is.'],
        ],
        edges: [
            ['from' => 'start', 'slot' => 0, 'to' => 'gate'],
            ['from' => 'gate', 'slot' => 0, 'to' => 'cancel'],
            ['from' => 'gate', 'slot' => 1, 'to' => 'sorry'],
            ['from' => 'sorry', 'slot' => 0, 'to' => 'cancel'],
        ],
    ));

    $scenario->refresh();

    expect(issueKeys($this->graph->issues($scenario), ScenarioGraph::BLOCKING))->toBe(['cancel']);

    expect(fn (): ScenarioVersion => $this->versions->publish($scenario))
        ->toThrow(ScenarioNotPublishable::class);

    expect(ScenarioVersion::query()->where('scenario_id', $scenario->getKey())->count())->toBe(0)
        ->and($scenario->refresh()->live_version_id)->toBeNull();
});

/**
 * Publishing is refused for any blocking defect, and refusing has to leave
 * nothing behind: a version row written before the check, or a live pointer
 * moved to it, would put the broken scenario in front of customers while the
 * screen reported the failure.
 */
it('writes no version when a scenario is refused a release', function (): void {
    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'hello', 'type' => 'say', 'body' => 'Hi.'],
        ],
        edges: [['from' => 'start', 'slot' => 0, 'to' => 'missing']],
    ));

    try {
        $this->versions->publish($scenario->refresh());
        $this->fail('A scenario with a transition to nowhere was published.');
    } catch (ScenarioNotPublishable $exception) {
        expect($exception->issues)->not->toBeEmpty()
            ->and($exception->messages()[0])->toContain('missing');
    }

    expect($scenario->versions()->count())->toBe(0);
});

/**
 * Moving a node is not a change to the bot.
 *
 * The fingerprint is what the panel uses to say "there is something to
 * publish", and a fingerprint that moved when somebody tidied the canvas would
 * make that sentence appear after every session — until it is read as
 * decoration and a real change goes out unnoticed.
 */
it('keeps the same fingerprint when a node is dragged and a different one when the words change', function (): void {
    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'hello', 'type' => 'say', 'body' => 'Hi.'],
        ],
        edges: [['from' => 'start', 'slot' => 0, 'to' => 'hello']],
    ));

    $before = $this->versions->fingerprint($scenario->refresh());

    $scenario->steps()->where('key', 'hello')->update(['pos_x' => 640, 'pos_y' => 480]);

    expect($this->versions->fingerprint($scenario->refresh()))->toBe($before);

    $scenario->steps()->where('key', 'hello')->update(['body' => 'Hello there.']);

    expect($this->versions->fingerprint($scenario->refresh()))->not->toBe($before);
});

/**
 * A rollback publishes the old drawing again instead of deleting what came
 * after it. The history is the only account of what customers were told while
 * the bad version was live, and an incident is reconstructed from it days
 * later.
 */
it('rolls back by publishing the old content again and leaves the history alone', function (): void {
    $scenario = Scenario::factory()->create();

    $this->graph->apply($scenario, graphOf(
        nodes: [
            ['key' => 'start', 'type' => 'start'],
            ['key' => 'hello', 'type' => 'say', 'body' => 'Hi.'],
        ],
        edges: [['from' => 'start', 'slot' => 0, 'to' => 'hello']],
    ));

    $first = $this->versions->publish($scenario->refresh());

    expect($this->versions->hasUnpublishedChanges($scenario->refresh()))->toBeFalse();

    $scenario->steps()->where('key', 'hello')->update(['body' => 'Yo.']);

    expect($this->versions->hasUnpublishedChanges($scenario->refresh()))->toBeTrue();

    $second = $this->versions->publish($scenario->refresh());
    $third = $this->versions->rollBack($scenario->refresh(), $first);

    expect($third->number)->toBe(3)
        ->and($scenario->refresh()->live_version_id)->toBe($third->getKey())
        ->and($scenario->versions()->pluck('number')->all())->toBe([3, 2, 1])
        ->and(ScenarioVersion::query()->whereKey($second->getKey())->exists())->toBeTrue()
        ->and($scenario->steps()->where('key', 'hello')->value('body'))->toBe('Hi.')
        ->and($third->fingerprint)->toBe($first->fingerprint);
});
