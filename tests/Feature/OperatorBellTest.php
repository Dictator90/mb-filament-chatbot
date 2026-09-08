<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Chat\ChatDesk;
use MB\FilamentChatbot\Contracts\OperatorAudience;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Workbench\App\Models\User;

/**
 * The bell that says a conversation is waiting for a person.
 *
 * It did not exist. The node that gives a thread to a human carried a docblock
 * saying "this is the node the operator's notification hangs off", and no line
 * of the package sent one — so a handed-over conversation waited in silence
 * until somebody happened to look at the desk.
 *
 * Restraint is the other half of the design, and it is what the tests below
 * spend most of their assertions on: a bell that rings for threads the bot was
 * about to answer is one people stop opening within a week, taking the
 * conversation that genuinely needed them with it.
 */
function bellCount(): int
{
    return DB::table('notifications')->count();
}

function operatorOn(?Conversation $conversation = null): User
{
    $operator = User::create([
        'name' => 'Operator',
        'email' => uniqid('op', true).'@example.test',
        'password' => 'secret-secret',
    ]);

    $conversation?->forceFill(['assigned_to_id' => $operator->getKey()])->save();

    return $operator;
}

/**
 * A visitor's message arriving the way a channel delivers one.
 *
 * Half of these tests cannot use the engine helper the rest of the suite uses:
 * the bell for a thread the bot did not take is rung by the router, and the
 * rule it obeys — ring once for a waiting thread, not once per message — is
 * decided from state the router reads before the message lands.
 */
function channelDelivers(Conversation $conversation, string $body): void
{
    app(MessageRouter::class)->receive(new InboundMessage(
        channel: 'site',
        externalConversationId: (string) $conversation->external_id,
        externalMessageId: uniqid('m', true),
        body: $body,
        visitorKey: (string) $conversation->visitor_key,
    ));
}

/**
 * A scenario whose only move is to fetch a person.
 */
function handingOverBot(): int
{
    $scenario = Scenario::factory()->create(['bot_id' => null, 'slug' => 'hand', 'is_active' => true]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'human',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'human',
        'type' => 'handoff',
        'next_key' => null,
    ]);

    return $scenario->getKey();
}

/**
 * The bot ran out of answers and somebody is waiting — the one moment the bell
 * is unambiguous.
 *
 * Note what the conversation is *not*: assigned. It cannot be. A thread with an
 * operator on it silences the bot entirely, so every handoff there has ever
 * been happens on a thread nobody has taken. An audience that only knew how to
 * ring an assignee would therefore have been silent at exactly this moment,
 * while looking perfectly reasonable in its own unit test.
 */
it('rings the desk when the bot hands the conversation over', function (): void {
    handingOverBot();
    operatorOn();

    $conversation = Conversation::factory()->create();

    visitorSays($conversation, 'hello?');

    expect(bellCount())->toBe(1);
});

/**
 * A thread the bot picked up is not waiting for anybody.
 *
 * Ring here and the desk fills with conversations that were about to be handled
 * perfectly well. The bot calls a person itself when it needs one, and that
 * call has its own bell.
 */
it('stays quiet while the bot is answering', function (): void {
    $scenario = Scenario::factory()->create(['bot_id' => null, 'slug' => 'talk', 'is_active' => true]);

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

    operatorOn();

    $conversation = Conversation::factory()->create();

    channelDelivers($conversation, 'when do you close?');

    expect(bellCount())->toBe(0);
});

/**
 * The second message of an unanswered thread is the same person, still waiting.
 *
 * A bell for it says nothing the first did not, and three of them in a row is
 * how a notification list becomes something people clear without reading.
 */
it('rings once for a thread nobody has picked up, not once per message', function (): void {
    operatorOn();

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');
    channelDelivers($conversation, 'anybody there?');
    channelDelivers($conversation, 'hello???');

    expect(bellCount())->toBe(1);
});

/**
 * An enquiry from a website arrives with nobody responsible for it, and that is
 * the case the bell exists for. Ringing nobody until a host configures a rota
 * would mean the shipped default is silent precisely where silence loses the
 * customer.
 */
it('rings everybody who may open the desk when nobody has the thread', function (): void {
    operatorOn();
    operatorOn();

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');

    expect(bellCount())->toBe(2);
});

/**
 * The desk is a permission, not a table.
 *
 * A host that has an opinion about who may read conversations has already said
 * who belongs at the desk, and the bell asks the same question every screen in
 * the package asks. Ringing somebody who cannot open what the bell links to is
 * a notification with nowhere to go.
 */
it('leaves out anybody the host will not let read conversations', function (): void {
    $allowed = operatorOn();
    operatorOn();

    Gate::define(
        'chatbot.view.conversations',
        fn (User $user): bool => $user->getKey() === $allowed->getKey(),
    );

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');

    expect(bellCount())->toBe(1)
        ->and(DB::table('notifications')->value('notifiable_id'))->toBe($allowed->getKey());
});

/**
 * A desk of two hundred people is not a desk.
 *
 * It means the operator model is the site's whole users table, customers
 * included, and the useful failure there is a quiet bell the doctor names —
 * not one notification per customer per enquiry, sent before anybody notices.
 */
it('goes quiet rather than notifying a whole users table', function (): void {
    config()->set('chatbot.notifications.max_recipients', 1);

    operatorOn();
    operatorOn();

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');

    expect(bellCount())->toBe(0);
});

/**
 * A thread with an owner rings its owner and nobody else: calling four people
 * to one reply is how a desk learns to ignore its own notifications.
 */
it('tells the assignee alone', function (): void {
    $conversation = Conversation::factory()->create(['bot_id' => null]);
    $operator = operatorOn($conversation);

    operatorOn();

    channelDelivers($conversation, 'are you still there?');

    expect(bellCount())->toBe(1)
        ->and(DB::table('notifications')->value('notifiable_id'))->toBe($operator->getKey());
});

/**
 * A host whose answer is narrower than a permission — a rota, a duty shift —
 * says so by binding the contract, and the shipped default steps aside.
 */
it('lets the host name the audience instead', function (): void {
    operatorOn();

    $duty = User::create([
        'name' => 'On duty',
        'email' => 'duty@example.test',
        'password' => 'secret-secret',
    ]);

    app()->instance(OperatorAudience::class, new class($duty) implements OperatorAudience
    {
        public function __construct(private readonly User $duty) {}

        public function for(Conversation $conversation): iterable
        {
            return [$this->duty];
        }
    });

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');

    expect(bellCount())->toBe(1)
        ->and(DB::table('notifications')->value('notifiable_id'))->toBe($duty->getKey());
});

/**
 * A host audience that falls over must not cost the customer their message.
 *
 * The bell is rung from inside the path that stores what was said; an exception
 * escaping would answer the channel with a failure, after which the webhook
 * redelivers and it throws again.
 */
it('stores the message even when the audience throws', function (): void {
    app()->instance(OperatorAudience::class, new class implements OperatorAudience
    {
        public function for(Conversation $conversation): iterable
        {
            throw new RuntimeException('The staff directory is down.');
        }
    });

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello');

    expect($conversation->refresh()->messages()->count())->toBe(1)
        ->and(bellCount())->toBe(0);
});

/**
 * Turning the desk off is a supported answer, not a broken one: some hosts
 * escalate unassigned threads by their own rules and want the package quiet
 * until a thread has an owner.
 */
it('can be told to leave unassigned threads alone', function (): void {
    config()->set('chatbot.notifications.unassigned', false);

    operatorOn();

    $conversation = Conversation::factory()->create(['bot_id' => null]);

    channelDelivers($conversation, 'hello?');

    expect(bellCount())->toBe(0)
        ->and(iterator_to_array((new ChatDesk)->for($conversation->refresh()), false))->toBe([]);
});
