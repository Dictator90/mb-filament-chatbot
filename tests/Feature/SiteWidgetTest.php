<?php

declare(strict_types=1);

use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

beforeEach(function (): void {
    config([
        'chatbot.widget.enabled' => true,
        'chatbot.widget.origins' => ['https://shop.example.com'],
    ]);

    $this->bot = Bot::factory()->create(['slug' => 'demo']);
});

/**
 * Builds a conversation the widget can legitimately reach, and the token that
 * reaches it.
 *
 * @return array{0: Conversation, 1: string}
 */
function visitorThread(): array
{
    $token = VisitorToken::issue();

    $conversation = Conversation::factory()->create([
        'channel' => 'site',
        'visitor_key' => VisitorToken::hash($token),
    ]);

    return [$conversation, $token];
}

/**
 * The allow-list is the whole of the widget's perimeter. Everything behind it
 * costs money — a conversation, a model call — and the endpoint is public by
 * necessity, since the browser calling it is on somebody else's domain.
 */
it('answers a site that is on the allow-list', function (): void {
    [, $token] = visitorThread();

    $this->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $token,
    ])->getJson('/chatbot/site/messages')->assertOk();
});

it('refuses a site that is not on the allow-list', function (): void {
    [, $token] = visitorThread();

    $this->withHeaders([
        'Origin' => 'https://someone-elses-site.example',
        'X-Chatbot-Token' => $token,
    ])->getJson('/chatbot/site/messages')->assertForbidden();
});

/**
 * An empty list blocks everybody rather than allowing everybody.
 *
 * The reverse default is the one that turns a forgotten setting into an
 * endpoint any site can embed and bill to this application's model budget —
 * and nothing about the installation would look wrong.
 */
it('refuses every site while the allow-list is empty', function (): void {
    config(['chatbot.widget.origins' => []]);

    [, $token] = visitorThread();

    $this->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $token,
    ])->getJson('/chatbot/site/messages')->assertForbidden();
});

/**
 * Scheme and host together, never the host alone: `http://shop.example.com` is
 * a different origin from `https://shop.example.com`, and treating them as one
 * would let a plaintext page read a conversation.
 */
it('does not accept the same host over a different scheme', function (): void {
    [, $token] = visitorThread();

    $this->withHeaders([
        'Origin' => 'http://shop.example.com',
        'X-Chatbot-Token' => $token,
    ])->getJson('/chatbot/site/messages')->assertForbidden();
});

/**
 * The token is the only thing standing between one visitor and another's
 * transcript, and the widget runs on a page the visitor can read entirely.
 */
it('does not hand somebody else the wrong conversation', function (): void {
    [$conversation] = visitorThread();

    Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Incoming,
        'author' => MessageAuthor::Visitor,
        'body' => 'my card number is on this page',
    ]);

    $response = $this->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => VisitorToken::issue(),
    ])->getJson('/chatbot/site/messages')->assertOk();

    expect($response->json('messages'))->toBe([]);
});

/**
 * Only the hash is kept. A stolen database should not be a drawer full of keys
 * to live conversations.
 */
it('stores the visitor token hashed and never in the clear', function (): void {
    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello'])
        ->assertOk();

    $token = $response->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    $conversation = Conversation::query()->latest('id')->firstOrFail();

    expect($conversation->visitor_key)->toBe(VisitorToken::hash($token))
        ->and($conversation->visitor_key)->not->toBe($token);
});

it('records what the visitor said and lets the bot take its turn', function (): void {
    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello there'])
        ->assertOk();

    $conversation = Conversation::query()->latest('id')->firstOrFail();

    expect($conversation->messages()->count())->toBeGreaterThan(0)
        ->and($conversation->channel)->toBe('site');
});

/**
 * A message longer than the column is a database error in production and a
 * validation message here.
 */
it('refuses a message longer than the configured limit', function (): void {
    config(['chatbot.chat.max_message_length' => 20]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', [
            'bot' => 'demo',
            'body' => str_repeat('a', 200),
        ])
        ->assertStatus(422);
});

/**
 * A widget switched off is off for everybody, allow-list or not.
 */
it('refuses everything while the widget is switched off', function (): void {
    config(['chatbot.widget.enabled' => false]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->getJson('/chatbot/site/messages')
        ->assertForbidden();
});

/**
 * Served rather than published, so that installing the package is enough.
 *
 * Held for an hour with an ETag rather than marked immutable: the URL carries
 * no version, so an immutable copy would outlive the upgrade that changed the
 * file and a visitor would keep the old widget until they cleared their cache.
 */
it('serves the widget script and stylesheet to anybody', function (): void {
    $this->get('/chatbot/widget.js')
        ->assertOk()
        ->assertHeader('content-type', 'application/javascript; charset=utf-8');

    $this->get('/chatbot/widget.css')->assertOk();
});

/**
 * Which sites may embed the widget is the one setting that differs between
 * every environment a host has, and it can be given without publishing the
 * config file.
 *
 * The config is read here rather than exercised through a request, because what
 * is being checked is the parsing: a host that writes the variable and gets a
 * list of one string containing commas has a widget that admits nobody, and the
 * only symptom is a widget that never opens on a site that is plainly listed.
 *
 * Blank entries are dropped rather than kept. A trailing comma is the most
 * ordinary thing to leave in an env file, and an empty string in this list is
 * not harmless — it is an origin that matches nothing, sitting in a list whose
 * emptiness is the very thing `chatbot:doctor` reports on.
 */
it('takes the sites that may embed it from one environment variable', function (): void {
    $read = static function (?string $value): array {
        $previous = getenv('CHATBOT_WIDGET_ORIGINS');

        $value === null
            ? putenv('CHATBOT_WIDGET_ORIGINS')
            : putenv('CHATBOT_WIDGET_ORIGINS='.$value);

        try {
            return (array) (require dirname(__DIR__, 2).'/config/chatbot.php')['widget']['origins'];
        } finally {
            $previous === false
                ? putenv('CHATBOT_WIDGET_ORIGINS')
                : putenv('CHATBOT_WIDGET_ORIGINS='.$previous);
        }
    };

    expect($read('https://shop.example.com'))->toBe(['https://shop.example.com'])
        ->and($read('https://shop.example.com, https://www.example.com'))
        ->toBe(['https://shop.example.com', 'https://www.example.com'])
        ->and($read('https://shop.example.com,,'))->toBe(['https://shop.example.com'])
        ->and($read(''))->toBe([])
        ->and($read(null))->toBe([]);
});
