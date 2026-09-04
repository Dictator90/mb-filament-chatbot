<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Bot\Http\OutboundGuard;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\Types\ForEachStep;
use MB\FilamentChatbot\Bot\Steps\Types\HttpStep;
use MB\FilamentChatbot\Bot\Steps\Types\TransformStep;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Tests\Support\LoopbackGuard;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * The three nodes that compute rather than converse, and the faults in them
 * that no transcript shows.
 *
 * The outbound node is where most of this file is spent, because it is the only
 * node in the package that can be turned against the application that installs
 * it: a URL typed into a web form, sent from inside the private network, with
 * the visitor's own words in it. A guard that reads the text of an address
 * instead of what the address resolves to looks exactly like a guard that works.
 *
 * The other two fail more quietly still — a variable written over with a value
 * an operation never produced, and a loop that walks a list nobody bounded until
 * the visitor gives up on a chat that is still thinking.
 */

/**
 * @param  array<string, mixed>  $config
 */
function computeNode(string $type, array $config, string $key = 'node'): Step
{
    return Step::factory()->make(['key' => $key, 'type' => $type, 'body' => null, 'config' => $config]);
}

function computeContext(Step $step, RecordingRuntime $runtime, ?Conversation $conversation = null): StepContext
{
    return new StepContext($conversation ?? new Conversation, new Scenario, $step, $runtime);
}

/**
 * @param  array<string, mixed>  $config
 */
function callOut(array $config): StepResult
{
    return app(HttpStep::class)->run(computeContext(
        computeNode('http', $config + ['into' => 'answer']),
        new RecordingRuntime,
    ));
}

beforeEach(function (): void {
    Http::fake(['*' => Http::response('{"data":{"status":"paid"}}', 200)]);
});

/**
 * The default state of a fresh installation is a node that cannot reach
 * anything. The reverse default — allow everything until somebody writes a
 * restriction — turns a forgotten setting into a node any panel user can point
 * at an internal address.
 */
it('refuses to call anything while no domain is allowed', function (): void {
    config()->set('chatbot.http.allowed_domains', []);

    $result = callOut(['url' => 'https://api.example.com/orders/1']);

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->outcome)->toBe(RunStep::FAILED)
        ->and($result->trace['reason'])->toContain('allowed domains');
});

/**
 * A scenario's request carries whatever the conversation has learned — a
 * reference number, a phone, a name. On the open network plain http is readable
 * and rewritable by whoever sits between.
 */
it('refuses plain http outside development', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('chatbot.http.allowed_domains', ['api.example.com']);

    $result = callOut(['url' => 'http://api.example.com/orders/1']);

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('plain http');
});

/**
 * Loopback reaches this application's own web server, and 169.254.169.254 is
 * the cloud metadata service — one request to which hands back the machine's
 * credentials. Both are ordinary-looking addresses somebody may put in the
 * allow-list.
 */
it('refuses private and reserved addresses even when they are allowed by name', function (string $url): void {
    config()->set('chatbot.http.allowed_domains', ['127.0.0.1', '169.254.169.254', '10.0.0.5']);

    $result = callOut(['url' => $url]);

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('private or reserved');
})->with([
    'https://127.0.0.1/orders/1',
    'https://169.254.169.254/latest/meta-data/',
    'https://10.0.0.5/admin',
]);

/**
 * The check the whole node is arranged around.
 *
 * A domain in the allow-list is a string somebody vetted once; what it resolves
 * to is its owner's decision, changeable at any time and invisible here. Read as
 * text, `api.example.com` pointing at 127.0.0.1 is indistinguishable from any
 * other allowed domain — which is why the range check has to run on the resolved
 * address and not on the URL.
 */
it('refuses an allowed domain whose name resolves to loopback', function (): void {
    app()->bind(OutboundGuard::class, LoopbackGuard::class);
    config()->set('chatbot.http.allowed_domains', ['api.example.com']);

    $result = callOut(['url' => 'https://api.example.com/orders/1']);

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('127.0.0.1');
});

/**
 * A redirect is the other system choosing where this request goes next, and
 * every check above ran against the host it chose to leave behind. Followed, a
 * 302 to the metadata service walks past the allow-list with the allow-list
 * still perfectly satisfied.
 *
 * Private addresses are permitted for this one and for the answer-reading test
 * below, because that setting is what stops the guard resolving the name at
 * all. Left off, these two would depend on what the machine running the suite
 * gets back for a domain nobody owns — a refusal on a laptop with no network, a
 * different one behind a resolver that answers everything, and half a minute of
 * DNS timeouts either way. What they are about is the request and the answer.
 */
it('does not follow redirects', function (): void {
    config()->set('chatbot.http.allowed_domains', ['api.example.com']);
    config()->set('chatbot.http.allow_private_networks', true);

    $options = null;

    Http::fake(function (Request $request, array $sent) use (&$options): mixed {
        $options = $sent;

        return Http::response('{"data":{"status":"paid"}}', 200);
    });

    callOut(['url' => 'https://api.example.com/orders/1']);

    expect($options)->not->toBeNull()
        ->and($options['allow_redirects'] ?? null)->toBeFalse();
});

/**
 * The ordinary path, and the one thing about it worth pinning: a path that is
 * absent from the answer is a failure, not an empty value. Written as an empty
 * string, "we asked the wrong system" reads to the visitor exactly like "the
 * order has no status".
 */
it('remembers the part of the answer it was pointed at, and fails when it is absent', function (): void {
    config()->set('chatbot.http.allowed_domains', ['example.com']);
    config()->set('chatbot.http.allow_private_networks', true);

    $runtime = new RecordingRuntime;
    $step = app(HttpStep::class);

    $found = $step->run(computeContext(computeNode('http', [
        'url' => 'https://api.example.com/orders/1',
        'path' => 'data.status',
        'into' => 'order',
    ]), $runtime));

    $missing = $step->run(computeContext(computeNode('http', [
        'url' => 'https://api.example.com/orders/1',
        'path' => 'data.shipped_at',
        'into' => 'shipped',
    ]), $runtime));

    expect($found->flow)->toBe(StepFlow::Next)
        ->and($runtime->stored['order'])->toBe('paid')
        ->and($missing->flow)->toBe(StepFlow::Second)
        ->and($runtime->stored)->not->toHaveKey('shipped');
});

/**
 * An operation this node does not know writes nothing at all. Copying the
 * source across instead is what turns a typo in a saved scenario into a
 * plausible sentence about the wrong thing — "we will call you on IVAN", from a
 * node whose author believed they were formatting a phone number.
 */
it('writes nothing when asked for an operation it does not have', function (): void {
    $runtime = new RecordingRuntime;
    $runtime->store('phone', '+7 (999) 000-11-22');
    $runtime->store('formatted', 'from an earlier turn');

    $result = app(TransformStep::class)->run(computeContext(computeNode('transform', [
        'source' => 'phone',
        'operation' => 'eval',
        'into' => 'formatted',
    ]), $runtime));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($result->outcome)->toBe(RunStep::FAILED)
        ->and($runtime->stored['formatted'])->toBe('from an earlier turn');
});

it('reshapes a value with the operations it does have', function (array $config, string $source, string $expected): void {
    $runtime = new RecordingRuntime;
    $runtime->store('in', $source);
    $runtime->store('surname', 'Petrov');

    app(TransformStep::class)->run(computeContext(
        computeNode('transform', $config + ['source' => 'in', 'into' => 'out']),
        $runtime,
    ));

    expect($runtime->stored['out'])->toBe($expected);
})->with([
    'lower' => [['operation' => 'lower'], 'IVAN', 'ivan'],
    'first word' => [['operation' => 'first_word'], 'Ivan Petrov junior', 'Ivan'],
    'digits only' => [['operation' => 'digits_only'], '+7 (999) 000-11-22', '79990001122'],
    'stand-in for an empty value' => [['operation' => 'default', 'fallback' => 'friend'], '   ', 'friend'],
    'join, dropping the empty ones' => [['operation' => 'join', 'with' => ['nothing', 'surname'], 'separator' => ' '], 'Ivan', 'Ivan Petrov'],
    'a date the way people read it' => [['operation' => 'format_date', 'format' => 'd.m.Y'], '2026-05-01', '01.05.2026'],
    'anything that is not a date is left alone' => [['operation' => 'format_date', 'format' => 'd.m.Y'], '+7 999 000', '+7 999 000'],
]);

/**
 * A loop that walks exactly as far as it was told to, and then leaves.
 *
 * The limit is the whole reason this node has a required field. Without a bound
 * the walk spins inside one turn until the engine's own ceiling stops it, and
 * the visitor is left looking at a chat that answered nothing — on the three
 * records the author's test data holds, that is indistinguishable from a loop
 * that works.
 */
it('walks exactly as many entries as its limit allows and then takes the done exit', function (): void {
    $conversation = Conversation::factory()->create();
    $runtime = new RecordingRuntime;
    $runtime->store('bookings', implode(PHP_EOL, ['one', 'two', 'three', 'four', 'five']));

    $step = app(ForEachStep::class);
    $config = ['source' => 'bookings', 'item' => 'booking', 'index' => 'number', 'max_items' => 2];

    $walked = [];
    $flows = [];

    foreach (range(1, 3) as $ignored) {
        $result = $step->run(computeContext(computeNode('for_each', $config), $runtime, $conversation->refresh()));

        $flows[] = $result->flow;

        if ($result->flow === StepFlow::Next) {
            $walked[] = $runtime->stored['booking'].':'.$runtime->stored['number'];
        }
    }

    expect($walked)->toBe(['one:1', 'two:2'])
        ->and($flows)->toBe([StepFlow::Next, StepFlow::Next, StepFlow::Second])
        ->and((array) $conversation->refresh()->meta)->not->toHaveKey('for_each:node');
});

/**
 * A loop with no bound is a defect that has to be named while the scenario is
 * open, because the person it costs is not the author.
 */
it('calls a loop without a finite limit a blocking defect', function (mixed $limit): void {
    $issues = app(ForEachStep::class)->issues(
        computeNode('for_each', ['source' => 'bookings', 'item' => 'booking', 'max_items' => $limit]),
        new Scenario,
    );

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['severity'])->toBe('blocking');
})->with([
    'not set' => [null],
    'zero' => [0],
    'above the ceiling' => [500],
]);

/**
 * And if one is saved anyway — by an import, by an older version of the editor —
 * it leaves through "done" rather than running unbounded.
 */
it('refuses to run a loop that has no finite limit', function (): void {
    $runtime = new RecordingRuntime;
    $runtime->store('bookings', implode(PHP_EOL, ['one', 'two']));

    $result = app(ForEachStep::class)->run(computeContext(computeNode('for_each', [
        'source' => 'bookings',
        'item' => 'booking',
    ]), $runtime, Conversation::factory()->create()));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->outcome)->toBe(RunStep::FAILED)
        ->and($runtime->stored)->not->toHaveKey('booking');
});
