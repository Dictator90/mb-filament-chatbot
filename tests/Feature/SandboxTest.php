<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Filament\Pages\Sandbox;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\EditScenarioGraph;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Sandbox\SandboxRunner;

/**
 * Builds a scenario from a compact description.
 *
 * A third copy of the engine suite's helper, named differently on purpose: Pest
 * loads every test file into one process, and two files declaring the same
 * global function is a fatal error nobody can read from the output.
 *
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, mixed>  $attributes
 */
function sandboxScenario(array $steps, array $attributes = []): Scenario
{
    $scenario = Scenario::factory()->create($attributes);

    foreach ($steps as $index => $step) {
        Step::factory()->create([
            'scenario_id' => $scenario->getKey(),
            'sort' => $index,
        ] + $step);
    }

    return $scenario->load('steps');
}

/**
 * @return list<string>
 */
function sandboxBotSaid(array $transcript): array
{
    return array_values(array_map(
        static fn (array $line): string => $line['body'],
        array_filter($transcript, static fn (array $line): bool => $line['from'] === 'bot'),
    ));
}

beforeEach(function (): void {
    $this->bot = Bot::factory()->create();
    $this->runner = app(SandboxRunner::class);
});

/**
 * The one property the whole sandbox is worth having for.
 *
 * A sandbox that walked the graph itself would agree with the drawing and
 * disagree with production — and it would do so most confidently about the
 * branches an author came here to check, because a hand-rolled walker follows
 * the arrows rather than the engine's own rules about waiting, matching and
 * branching. So this drives a two-turn conversation through the runner and
 * expects exactly what the engine does with it: break the engine and the
 * sandbox has to break with it.
 */
it('holds the conversation with the engine that answers customers', function (): void {
    $scenario = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'pick', 'config' => ['trigger' => 'fallback']],
        ['key' => 'pick', 'type' => 'ask', 'body' => 'Sales or support?', 'options' => [
            ['label' => 'Sales', 'next' => 'sales'],
            ['label' => 'Support', 'next' => 'support'],
        ]],
        ['key' => 'sales', 'type' => 'say', 'body' => 'Putting you through to sales.'],
        ['key' => 'support', 'type' => 'say', 'body' => 'Support here.'],
    ], ['bot_id' => $this->bot->getKey()]);

    $conversation = $this->runner->open('graph:1:7', $this->bot);

    $this->runner->say($conversation, 'hello', $scenario);

    expect(sandboxBotSaid($this->runner->transcript($conversation)))->toBe(['Sales or support?'])
        ->and($conversation->refresh()->bot_step_key)->toBe('pick')
        ->and($this->runner->state($conversation))->toBe('waiting');

    $this->runner->say($conversation->refresh(), 'support', $scenario);

    expect(sandboxBotSaid($this->runner->transcript($conversation)))
        ->toBe(['Sales or support?', 'Support here.']);
});

/**
 * A sandbox thread looks exactly like a customer's, and an operator who opens
 * one answers somebody who does not exist — while every report counts the
 * exchange as work. The same global scope that hides a quality rehearsal is what
 * keeps it out, and this proves the sandbox is marked in the way that scope
 * reads rather than in some way of its own.
 */
it('keeps sandbox conversations off the desk and out of every ordinary query', function (): void {
    signIn();

    $real = Conversation::factory()->create(['last_message_at' => now()]);
    $sandbox = $this->runner->open(SandboxRunner::ownerKey('sandbox', 1), $this->bot);

    expect(Conversation::query()->pluck('id')->all())->not->toContain($sandbox->getKey());

    $listed = Livewire::test(ChatInbox::class)->instance()->conversations->pluck('id');

    expect($listed)->toContain($real->getKey())
        ->and($listed)->not->toContain($sandbox->getKey());
});

/**
 * The trace is read from the run journal and nowhere else.
 *
 * Which branch was taken is the whole question a sandbox is opened to answer, so
 * a trace worked out from the scenario would answer with the branch the author
 * drew — the very thing under test — and would agree with a broken bot every
 * time. Here the visitor's answer sends the conversation down one branch, and
 * the node on the other one must not appear even though it is a node away.
 */
it('shows the nodes the conversation really walked, not the ones it was drawn to walk', function (): void {
    $scenario = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'pick', 'config' => ['trigger' => 'fallback']],
        ['key' => 'pick', 'type' => 'ask', 'body' => 'Sales or support?', 'options' => [
            ['label' => 'Sales', 'next' => 'sales'],
            ['label' => 'Support', 'next' => 'support'],
        ]],
        ['key' => 'sales', 'type' => 'say', 'body' => 'Putting you through to sales.'],
        ['key' => 'support', 'type' => 'say', 'body' => 'Support here.'],
    ], ['bot_id' => $this->bot->getKey()]);

    $conversation = $this->runner->open('graph:1:7', $this->bot);

    $this->runner->say($conversation, 'hello', $scenario);
    $this->runner->say($conversation->refresh(), 'support', $scenario);

    $journal = RunStep::query()
        ->whereIn('run_id', Run::query()->select('id')->where('conversation_id', $conversation->getKey()))
        ->orderBy('run_id')
        ->orderBy('seq')
        ->pluck('step_key')
        ->unique()
        ->values()
        ->all();

    expect($this->runner->visited($conversation))->toBe($journal)
        ->and($this->runner->visited($conversation))->toContain('support')
        ->and($this->runner->visited($conversation))->not->toContain('sales');
});

/**
 * Node keys are unique inside a scenario and nowhere else.
 *
 * A conversation that jumped into another scenario carrying a node with the same
 * key would otherwise light up a node on this canvas that the visitor never
 * reached — and a highlight that is sometimes about another scenario is worse
 * than none, because nothing on screen says which time it is.
 */
it('lights up only the nodes of the scenario whose canvas is open', function (): void {
    $elsewhere = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'answer', 'config' => ['trigger' => 'keyword', 'keywords' => ['nothing']]],
        ['key' => 'answer', 'type' => 'say', 'body' => 'Somewhere else entirely.'],
    ], ['bot_id' => $this->bot->getKey(), 'slug' => 'elsewhere']);

    $here = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'leave', 'config' => ['trigger' => 'fallback']],
        ['key' => 'leave', 'type' => 'jump', 'config' => ['scenario' => $elsewhere->slug]],
        ['key' => 'answer', 'type' => 'say', 'body' => 'Never reached.'],
    ], ['bot_id' => $this->bot->getKey(), 'slug' => 'here']);

    $conversation = $this->runner->open('graph:2:7', $this->bot);

    $this->runner->say($conversation, 'hello', $here);

    expect($this->runner->visited($conversation))->toContain('answer')
        ->and($this->runner->visited($conversation, 'here'))->toBe(['start', 'leave'])
        ->and($this->runner->visited($conversation, 'here'))->not->toContain('answer');
});

/**
 * The canvas tests one drawing, whatever the routing would have done.
 *
 * A scenario triggered by a keyword is unreachable from a sandbox that let the
 * router choose unless the author guesses its own keywords, and a canvas whose
 * conversation was answered by a different scenario highlights nothing at all —
 * which reads as the sandbox being broken. Here a fallback scenario would have
 * claimed the message, and the canvas's own scenario must answer it regardless.
 */
it('enters the scenario the canvas is drawing, even when routing would pick another', function (): void {
    signIn();

    sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'catch', 'config' => ['trigger' => 'fallback']],
        ['key' => 'catch', 'type' => 'say', 'body' => 'The catch-all answered.'],
    ], ['bot_id' => $this->bot->getKey(), 'priority' => 10]);

    $drawn = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'refund', 'config' => ['trigger' => 'keyword', 'keywords' => ['refund']]],
        ['key' => 'refund', 'type' => 'say', 'body' => 'Refunds take three days.'],
    ], ['bot_id' => $this->bot->getKey()]);

    $page = Livewire::test(EditScenarioGraph::class, ['record' => $drawn->getKey()]);

    $page->call('sandboxSay', 'hello there');

    $state = $page->instance()->sandboxState();

    expect(sandboxBotSaid($state['transcript']))->toBe(['Refunds take three days.'])
        ->and($state['visited'])->toContain('refund');
});

/**
 * The sandbox screen exists for the question a single canvas cannot ask.
 *
 * Which scenario answers is decided by the router, and that decision is where an
 * installation with a dozen scenarios actually goes wrong — a keyword claimed by
 * two of them, a fallback priced high enough to swallow every keyword. So this
 * screen must not pin anything: the same bot, two different messages, two
 * different scenarios answering.
 */
it('lets the router choose the scenario on the sandbox screen', function (): void {
    signIn();

    sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'refund', 'config' => ['trigger' => 'keyword', 'keywords' => ['refund']]],
        ['key' => 'refund', 'type' => 'say', 'body' => 'Refunds take three days.'],
    ], ['bot_id' => $this->bot->getKey(), 'slug' => 'refunds']);

    sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'catch', 'config' => ['trigger' => 'fallback']],
        ['key' => 'catch', 'type' => 'say', 'body' => 'A person will be with you.'],
    ], ['bot_id' => $this->bot->getKey(), 'slug' => 'catch-all']);

    $page = Livewire::test(Sandbox::class)
        ->set('botId', $this->bot->getKey())
        ->set('said', 'where is my refund')
        ->call('send');

    expect(sandboxBotSaid($page->instance()->transcript))->toBe(['Refunds take three days.'])
        ->and(collect($page->instance()->trace)->pluck('scenario')->unique()->all())->toBe(['refunds']);

    $page->call('restart')
        ->set('said', 'good morning')
        ->call('send');

    expect(sandboxBotSaid($page->instance()->transcript))->toBe(['A person will be with you.'])
        ->and(collect($page->instance()->trace)->pluck('scenario')->unique()->all())->toBe(['catch-all']);
});

/**
 * A sandbox must not be able to send anybody anything.
 *
 * Every outgoing message queues a delivery, and on a live installation a
 * sandbox reply would otherwise be pushed to whoever the channel believes the
 * thread belongs to — a customer receiving half a rehearsal at two in the
 * morning. Asserted on the row rather than on the queue: a fake queue reports a
 * pushed job for a synchronous dispatch just the same, so the only honest
 * question is whether delivery actually happened.
 */
it('delivers nothing while somebody is trying the bot out', function (): void {
    $scenario = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ], ['bot_id' => $this->bot->getKey()]);

    $conversation = $this->runner->open('graph:9:7', $this->bot);

    $this->runner->say($conversation, 'hello', $scenario);

    $replies = $conversation->messages()->where('direction', MessageDirection::Outgoing)->get();

    expect($replies)->toHaveCount(1)
        ->and($replies->first()->delivered_at)->toBeNull()
        ->and($replies->first()->failed_at)->toBeNull();
});

/**
 * The screen refuses whoever the host refuses.
 *
 * Every consequence of getting this wrong is silent: the sandbox writes real
 * conversations against real data resources, and a screen open to everybody
 * simply appears in the menu with nothing to say it should not have.
 */
it('offers the sandbox screen only to somebody allowed to see scenarios', function (): void {
    signIn();

    expect(Sandbox::canAccess())->toBeTrue();

    Gate::define('chatbot.view.scenarios', fn (): bool => false);

    expect(Sandbox::canAccess())->toBeFalse();
});

/**
 * A sandbox used every day for a month must not leave a month of threads behind.
 *
 * One per person per screen, replaced rather than added to, and the messages and
 * runs go with it. The alternative is a table nobody can see into growing for
 * the life of the installation — invisible to every screen because of the very
 * scope that makes the sandbox safe.
 */
it('keeps one thread per person and takes its messages with it when it is replaced', function (): void {
    $scenario = sandboxScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.'],
    ], ['bot_id' => $this->bot->getKey()]);

    $owner = SandboxRunner::ownerKey('sandbox', 3);

    $first = $this->runner->open($owner, $this->bot);
    $this->runner->say($first, 'hello', $scenario);

    $second = $this->runner->restart($owner, $this->bot);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($this->runner->transcript($second))->toBe([]);

    $left = Conversation::query()
        ->withoutGlobalScope(Conversation::REAL_SCOPE)
        ->whereNotNull('meta->'.Conversation::SANDBOX_META_KEY)
        ->count();

    expect($left)->toBe(1)
        ->and(Run::query()->where('conversation_id', $first->getKey())->count())->toBe(0);
});

/**
 * Abandoned threads go on their own, because a package cannot assume the host
 * runs a scheduler — and a cleanup that has to be configured is a cleanup that
 * does not happen. Somebody else opening a sandbox is what sweeps them.
 */
it('sweeps sandbox threads nobody has touched for longer than the window', function (): void {
    $stale = $this->runner->open(SandboxRunner::ownerKey('sandbox', 11), $this->bot);
    $stale->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

    $this->runner->open(SandboxRunner::ownerKey('sandbox', 12), $this->bot);

    $survivors = Conversation::query()
        ->withoutGlobalScope(Conversation::REAL_SCOPE)
        ->whereNotNull('meta->'.Conversation::SANDBOX_META_KEY)
        ->pluck('id')
        ->all();

    expect($survivors)->not->toContain($stale->getKey());
});
