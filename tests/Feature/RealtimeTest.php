<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use MB\FilamentChatbot\Channels\RealtimeSettings;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Events\MessageArrived;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Widget;

/**
 * Live updates are an optimisation over a transport that already works. Every
 * test here is about that sentence staying true: polling must survive the
 * feature being on, and nothing may reach a socket that has not been allowed
 * through the same door as everything else.
 */
beforeEach(function (): void {
    config([
        'chatbot.widget.enabled' => true,
        'chatbot.widget.origins' => ['https://shop.example.com'],
        'chatbot.realtime.enabled' => true,
        'chatbot.realtime.driver' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'options' => ['host' => 'sockets.example.test', 'port' => 8080, 'scheme' => 'http'],
        ],
    ]);

    $this->bot = Bot::factory()->create(['slug' => 'demo']);
    $this->widget = Widget::factory()->create(['key' => 'demo', 'bot_id' => $this->bot->getKey()]);
});

/**
 * @return array{0: Conversation, 1: string}
 */
function watchedThread(): array
{
    $token = VisitorToken::issue();

    $conversation = Conversation::factory()->create([
        'channel' => 'site',
        'visitor_key' => VisitorToken::hash($token),
    ]);

    return [$conversation, $token];
}

/**
 * The one thing that must never be broadcast is the message.
 *
 * A payload carrying the body would be a second way out of the table with none
 * of the rules the history endpoint applies — and broadcasting is the one place
 * where that mistake is delivered to everybody subscribed, at once, before
 * anybody notices. The announcement says a message exists; fetching it goes
 * through the door that checks who is asking.
 */
it('announces that a message exists and never what it says', function (): void {
    [$conversation] = watchedThread();

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Your refund is on its way, Mr Ivanov.',
    ]);

    $payload = (new MessageArrived($message))->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'incoming'])
        ->and(json_encode($payload))->not->toContain('refund')
        ->and(json_encode($payload))->not->toContain('Ivanov');
});

/**
 * The channel is named by the conversation's visitor key, not by its id.
 *
 * The name reaches a script on a customer's page. A sequential id there is both
 * an invitation to try the neighbouring number and a public count of how many
 * conversations this installation has ever had.
 */
it('names the channel by something that is not a counter', function (): void {
    [$conversation] = watchedThread();

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'hello',
    ]);

    $channels = (new MessageArrived($message))->broadcastOn();

    /*
     * Compared whole rather than by asking whether the id appears in it. A hex
     * digest contains almost every digit somewhere, so "the name does not
     * contain the id" fails on a name that is perfectly correct — a test that
     * would have to be deleted the first time it went off.
     */
    expect($channels[0]->name)->toBe('private-chatbot.conversation.'.$conversation->visitor_key);
});

/**
 * With live updates off, nothing is broadcast — and the event is still
 * dispatched.
 *
 * Both halves matter. Broadcasting regardless would make every installation
 * depend on a broadcasting connection being configured, and a missing one
 * throws while the message is being saved: a visitor's message lost to a
 * feature nobody switched on. Not dispatching at all would leave a host with
 * two code paths to listen to depending on a setting they did not set.
 */
it('dispatches the event either way and broadcasts only when switched on', function (): void {
    [$conversation] = watchedThread();

    Event::fake([MessageArrived::class]);

    config(['chatbot.realtime.enabled' => false]);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'hello',
    ]);

    Event::assertDispatched(MessageArrived::class);

    expect((new MessageArrived($message))->broadcastWhen())->toBeFalse();

    config(['chatbot.realtime.enabled' => true]);

    expect((new MessageArrived($message))->broadcastWhen())->toBeTrue();
});

/**
 * A subscription is admitted to the asking visitor's own conversation and to no
 * other.
 *
 * The request names the channel it wants. Taken at face value that is a reader
 * asking to be sent somebody else's conversation live, for as long as it lasts
 * — which is worse than any single unauthorised read, because it keeps
 * arriving.
 */
it('admits a visitor to their own channel and refuses every other', function (): void {
    [, $token] = watchedThread();
    [, $other] = watchedThread();

    $mine = 'private-chatbot.conversation.'.VisitorToken::hash($token);
    $theirs = 'private-chatbot.conversation.'.VisitorToken::hash($other);

    $this->withHeaders(['Origin' => 'https://shop.example.com', 'X-Chatbot-Token' => $token])
        ->postJson('/chatbot/site/socket', ['socket_id' => '123.456', 'channel_name' => $mine])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->withHeaders(['Origin' => 'https://shop.example.com', 'X-Chatbot-Token' => $token])
        ->postJson('/chatbot/site/socket', ['socket_id' => '123.456', 'channel_name' => $theirs])
        ->assertForbidden();

    /*
     * The headers are flushed first, and that is not tidiness. `withHeaders()`
     * merges into the test's own defaults and keeps them for every later request
     * in the same test, so a call written to prove "no token is refused" would
     * still be carrying the token from two requests ago — and would pass by
     * being authorised rather than by being refused.
     */
    $this->flushHeaders();

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/socket', ['socket_id' => '123.456', 'channel_name' => $mine])
        ->assertForbidden();
});

/**
 * The signature is the one the protocol asks for, computed from a secret that
 * never leaves the server.
 */
it('signs a subscription with the connection secret', function (): void {
    [, $token] = watchedThread();

    $channel = 'private-chatbot.conversation.'.VisitorToken::hash($token);

    $auth = $this->withHeaders(['Origin' => 'https://shop.example.com', 'X-Chatbot-Token' => $token])
        ->postJson('/chatbot/site/socket', ['socket_id' => '123.456', 'channel_name' => $channel])
        ->json('auth');

    expect($auth)->toBe('app-key:'.hash_hmac('sha256', '123.456:'.$channel, 'app-secret'));
});

/**
 * Switched off, the endpoint refuses rather than answering with a signature
 * nobody can use.
 */
it('refuses to sign anything while live updates are off', function (): void {
    config(['chatbot.realtime.enabled' => false]);

    [, $token] = watchedThread();

    $this->withHeaders(['Origin' => 'https://shop.example.com', 'X-Chatbot-Token' => $token])
        ->postJson('/chatbot/site/socket', [
            'socket_id' => '123.456',
            'channel_name' => 'private-chatbot.conversation.'.VisitorToken::hash($token),
        ])
        ->assertForbidden();
});

/**
 * What the widget is told carries no secret, and a half-configured connection
 * is announced as off.
 *
 * The app key is an identifier and is meant to be seen; the secret it is paired
 * with signs subscriptions and must never be. And a connection missing either
 * half is not "partly on" — announced that way, the widget opens a socket that
 * cannot finish a handshake and spends the connect timeout not updating before
 * falling back to the poll it could have used immediately.
 */
it('tells the widget how to connect without telling it the secret', function (): void {
    $settings = RealtimeSettings::resolve()->toArray();

    expect($settings['enabled'])->toBeTrue()
        ->and($settings['key'])->toBe('app-key')
        ->and($settings['scheme'])->toBe('ws')
        ->and(json_encode($settings))->not->toContain('app-secret');

    config(['broadcasting.connections.reverb.key' => '']);

    expect(RealtimeSettings::resolve()->enabled())->toBeFalse();

    config([
        'broadcasting.connections.reverb.key' => 'app-key',
        'broadcasting.connections.reverb.options.host' => '',
    ]);

    expect(RealtimeSettings::resolve()->enabled())->toBeFalse();
});
