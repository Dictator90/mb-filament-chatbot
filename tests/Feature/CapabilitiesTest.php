<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\ActionStep;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * Host actions, and the ways a scenario that calls one goes wrong in silence.
 *
 * Every failure below leaves a conversation that reads as though it worked: an
 * action nobody registered, a handler that threw where the visitor sees only a
 * chat that stopped, a payload adjusted to fit rather than refused — which is
 * how nought points get awarded and a booking gets cancelled with an id that
 * defaulted to nothing.
 */

/**
 * Registers an action and hands back the calls it received.
 *
 * The received payloads are the point of the spy: an input that was refused and
 * an input that was quietly repaired both end in a conversation carrying on, and
 * only the handler can say which one happened.
 *
 * The spy is an object rather than an array because an array captured by a
 * closure is captured by value — the calls would accumulate inside the closure's
 * own copy, every assertion about them would read an empty list, and each of
 * these tests would pass against a gateway that called the handler anyway.
 *
 * @param  list<Field>  $input
 * @param  Closure(array<string, mixed>): mixed  $handler
 */
function registerCapability(
    string $key,
    Closure $handler,
    array $input = [],
    SideEffect $effect = SideEffect::Read,
    string $version = '1',
): stdClass {
    $spy = new stdClass;
    $spy->calls = [];

    app(ChatbotRegistry::class)->registerCapability(new CapabilityAction(
        key: $key,
        version: $version,
        label: 'Award points',
        description: 'Adds loyalty points to the customer.',
        effect: $effect,
        input: $input,
        requiresApproval: $effect === SideEffect::Write,
        handler: function (array $payload, StepContext $context) use ($spy, $handler): mixed {
            $spy->calls[] = $payload;

            return $handler($payload);
        },
    ));

    return $spy;
}

/**
 * @param  array<string, mixed>  $config
 */
function actionNode(array $config): Step
{
    return Step::factory()->make(['key' => 'act', 'type' => 'action', 'body' => null, 'config' => $config]);
}

function actionContext(Step $step, RecordingRuntime $runtime): StepContext
{
    return new StepContext(new Conversation, new Scenario, $step, $runtime);
}

/**
 * The whole path, from a line in a host's provider to a variable a message can
 * read back: registered, picked by key, called with substituted values, and
 * named in the journal by key and version.
 *
 * Driven through the engine rather than through the node, because this is the
 * one assertion about capabilities that has to hold end to end — the node
 * resolving its own action proves nothing about a conversation reaching it.
 */
it('runs a host action inside a real conversation and names it in the trace', function (): void {
    $seen = registerCapability(
        'shop.award_points',
        fn (array $payload): array => ['balance' => 120, 'awarded' => $payload['points']],
        [Field::number('points')->required()],
        version: '2',
    );

    $scenario = Scenario::factory()->create(['bot_id' => null]);

    foreach ([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'award', 'config' => ['trigger' => 'fallback']],
        ['key' => 'award', 'type' => 'action', 'next_key' => 'tell', 'config' => [
            'capability' => 'shop.award_points',
            'input' => ['points' => '50'],
            'into' => 'award',
        ]],
        ['key' => 'tell', 'type' => 'say', 'body' => 'You now have {{award_balance}} points.'],
    ] as $sort => $step) {
        Step::factory()->create(['scenario_id' => $scenario->getKey(), 'sort' => $sort] + $step);
    }

    $conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);

    visitorSays($conversation, 'hello');

    $trace = RunStep::query()->where('step_key', 'award')->first();

    expect($seen->calls)->toBe([['points' => '50']])
        ->and(botSaid($conversation))->toBe(['You now have 120 points.'])
        ->and($trace)->not->toBeNull()
        ->and($trace->payload['capability'])->toBe('shop.award_points@2');
});

/**
 * A handler is host code and may do anything at all — reach a gateway that is
 * down, hit a unique index, touch a record deleted a second ago. Out of the
 * gateway that would end the visitor's turn with no reply, punishing them for
 * the state of a system they cannot see.
 */
it('takes the second exit when the host handler throws, instead of ending the conversation', function (): void {
    registerCapability('shop.award_points', function (array $payload): array {
        throw new RuntimeException('the loyalty service is down');
    });

    $runtime = new RecordingRuntime;

    $result = app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.award_points',
        'into' => 'award',
    ]), $runtime));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->outcome)->toBe(RunStep::FAILED)
        ->and($result->trace['reason'])->toContain('the loyalty service is down')
        ->and($runtime->stored)->toBe([]);
});

/**
 * A payload that does not fit the description is refused, and the handler is
 * never reached. Repaired instead — a missing value read as an empty string, a
 * word read as zero — the action still runs, still reports success, and awards
 * nought points to whoever asked.
 */
it('refuses a payload that does not match the descriptors rather than adjusting it', function (): void {
    $seen = registerCapability(
        'shop.award_points',
        fn (array $payload): array => ['ok' => true],
        [Field::number('points')->required(), Field::text('reason')->required()],
    );

    $runtime = new RecordingRuntime;
    $step = app(ActionStep::class);

    $missing = $step->run(actionContext(actionNode([
        'capability' => 'shop.award_points',
        'input' => ['points' => '{{never_collected}}', 'reason' => 'birthday'],
        'into' => 'award',
    ]), $runtime));

    $notANumber = $step->run(actionContext(actionNode([
        'capability' => 'shop.award_points',
        'input' => ['points' => 'plenty', 'reason' => 'birthday'],
        'into' => 'award',
    ]), $runtime));

    expect($seen->calls)->toBe([])
        ->and($missing->flow)->toBe(StepFlow::Second)
        ->and($missing->trace['reason'])->toContain('[points]')
        ->and($notANumber->flow)->toBe(StepFlow::Second)
        ->and($notANumber->trace['reason'])->toContain('not a number')
        ->and($runtime->stored)->toBe([]);
});

/**
 * A select is refused the same way, and this is the one that matters for an
 * action whose options are the difference between refunding and cancelling.
 */
it('refuses a choice that is not one of the offered options', function (): void {
    $seen = registerCapability(
        'shop.change_order',
        fn (array $payload): array => ['ok' => true],
        [Field::select('operation')->required()->options(['refund' => 'Refund', 'cancel' => 'Cancel'])],
    );

    $result = app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.change_order',
        'input' => ['operation' => 'delete_everything'],
    ]), new RecordingRuntime));

    expect($seen->calls)->toBe([])
        ->and($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('refund, cancel');
});

/**
 * A handler that answers with anything but an array is a handler written
 * against a signature nobody read. Treated as an empty success the node would
 * take its "done" exit and the message below it would describe a result that
 * was never there.
 */
it('treats a handler that does not return an array as a failure', function (): void {
    registerCapability('shop.award_points', fn (array $payload): mixed => true);

    $result = app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.award_points',
        'into' => 'award',
    ]), new RecordingRuntime));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('instead of an array');
});

/**
 * An unregistered key leaves through the exit the author drew for it. Anything
 * else — a throw, or worse, the "done" exit — makes an uninstalled package look
 * like a completed action.
 */
it('leaves through the second exit when no action is registered under the key', function (): void {
    $result = app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.gone_away',
    ]), new RecordingRuntime));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->outcome)->toBe(RunStep::FAILED);
});

/**
 * Registration order is manifest order. A second package claiming a key that is
 * already taken would perform its own refund under the first one's name, and
 * the only sign of it is money moving while the journal still reads successful.
 */
it('keeps the action already registered under a key instead of replacing it', function (): void {
    registerCapability('shop.refund', fn (array $payload): array => ['who' => 'first']);
    registerCapability('shop.refund', fn (array $payload): array => ['who' => 'second']);

    $runtime = new RecordingRuntime;

    app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.refund',
        'into' => 'refund',
    ]), $runtime));

    expect($runtime->stored['refund_who'])->toBe('first');

    app(ChatbotRegistry::class)->forgetCapability('shop.refund');
    registerCapability('shop.refund', fn (array $payload): array => ['who' => 'deliberate']);

    app(ActionStep::class)->run(actionContext(actionNode([
        'capability' => 'shop.refund',
        'into' => 'refund',
    ]), $runtime));

    expect($runtime->stored['refund_who'])->toBe('deliberate');
});

/**
 * The pin carries the version, because that is the only thing a release can
 * compare. Pinned by key alone, an action whose meaning was deliberately
 * changed — points that now cost money, an input that became required — would
 * slide under a released scenario with nothing at all to show for it.
 */
it('pins the action with its version, not by key alone', function (): void {
    registerCapability('shop.award_points', fn (array $payload): array => [], version: '3');

    $pins = app(ActionStep::class)->pins(actionNode(['capability' => 'shop.award_points']));

    expect($pins)->toBe(['capabilities' => ['shop.award_points@3']]);
});

/**
 * The node's input points at a field the node actually declares.
 *
 * The editor draws the chosen action's own descriptors by looking up the
 * sibling field this one names. A name matching nothing is a typo with no
 * symptom: the lookup finds no capability, every author silently gets the
 * fallback box of free-text pairs, and the promise that an action's input is
 * drawn from its descriptors quietly stops being kept — which is the state this
 * package was in until somebody installed it into a real application and looked
 * at the screen.
 */
it('points the action input at a field the node declares', function (): void {
    $fields = collect(app(ActionStep::class)->fields())
        ->mapWithKeys(fn (Field $field): array => [$field->name() => $field->toArray()]);

    $input = $fields->get('input');

    expect($input['type'])->toBe(Field::CAPABILITY_INPUT)
        ->and($input['from'])->toBe('capability')
        ->and($fields->has($input['from']))->toBeTrue();
});

/**
 * The editor cannot see the graph from inside the gateway, so this is where a
 * write without a confirmation above it is named. A blocking defect for an
 * action that is not there at all, because that node fails on every single
 * conversation.
 */
it('reports a write as needing a confirmation and an unknown key as blocking', function (): void {
    registerCapability('shop.cancel', fn (array $payload): array => [], effect: SideEffect::Write);

    $step = app(ActionStep::class);
    $scenario = new Scenario;

    $write = $step->issues(actionNode(['capability' => 'shop.cancel']), $scenario);
    $unknown = $step->issues(actionNode(['capability' => 'shop.vanished']), $scenario);
    $empty = $step->issues(actionNode([]), $scenario);

    expect($write)->toHaveCount(1)
        ->and($write[0]['severity'])->toBe('notice')
        ->and($write[0]['message'])->toContain('confirmation')
        ->and($unknown[0]['severity'])->toBe('blocking')
        ->and($empty[0]['severity'])->toBe('blocking');
});
