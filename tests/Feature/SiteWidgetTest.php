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

    /*
     * Published to the site on purpose, and said out loud here.
     *
     * The column defaults to false — a bot is not on anybody's pages until
     * somebody puts it there — and until the runtime started reading the flag
     * these tests passed without it, which is precisely how the flag came to
     * mean nothing.
     */
    $this->bot = Bot::factory()->publicWidget()->create(['slug' => 'demo']);
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

/**
 * One script tag is the whole embed, so the widget fetches its own stylesheet.
 *
 * The README, the documentation and the panel's copy-paste box all promise a
 * single tag. Without this the widget still runs and still answers — it simply
 * has no styles, and unstyled it has no position of its own: the launcher and
 * the panel land in the page flow at the very bottom of the document, under the
 * footer, as a few lines of stray text. Nobody reads that as "the stylesheet is
 * missing". They read it as "the widget is broken" and go looking at origins and
 * bot slugs.
 *
 * It went unnoticed for the life of the package because the only page that
 * embedded the widget — the workbench demo — wrote the `<link>` by hand. That
 * link is gone, and the second half of this test keeps it gone: a demo page that
 * embeds more than a customer would is a demo that proves less than it appears
 * to.
 */
it('fetches its own stylesheet, because the embed is one tag', function (): void {
    /*
     * Read from disk rather than through the route: the endpoint answers with a
     * `BinaryFileResponse`, whose `getContent()` is `false`, and a test that
     * asserted against that would be asserting against nothing at all.
     *
     * Comments are stripped before matching, and that is not tidiness. The first
     * version of this check searched the whole file and stayed green after the
     * `rel = 'stylesheet'` line was deleted — the words it was looking for were
     * sitting in the comment above the code explaining what the code did. A test
     * satisfied by its own subject's prose is worse than no test.
     */
    $widget = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(dirname(__DIR__, 2).'/resources/widget/widget.js'),
    );

    expect($widget)->toContain('/chatbot/widget.css')
        ->and($widget)->toContain('stylesheet');

    $this->get('/chatbot/widget.css')->assertOk();

    /*
     * The demo page is read from disk too, and not requested: the workbench's
     * routes are not registered in the suite, so a request for `/demo` answers
     * 404 and the assertion below would pass on a page it never saw.
     */
    $demo = (string) file_get_contents(dirname(__DIR__, 2).'/workbench/routes/web.php');

    expect($demo)->toContain('/chatbot/widget.js')
        ->and($demo)->not->toContain('widget.css');
});

/**
 * Unticking "answers on the site" actually takes the bot off the site.
 *
 * The column, the cast, the form field and the demo seed all existed; nothing
 * read it. A setting that saves and does nothing is worse than one that is
 * missing — the person who unticked it has been told the bot is off their pages
 * and has no reason to check, and it answers every visitor exactly as before.
 */
/**
 * The widget asks how it should look before it draws anything.
 *
 * Behind the same allow-list as everything else: the endpoint is harmless — it
 * answers with colours — but an endpoint outside the list is a second door, and
 * the second door is the one nobody remembers when the rules change.
 */
it('tells the widget how it should look', function (): void {
    $this->bot->update(['settings' => ['widget' => ['accent' => '#0ea5e9', 'bubbles' => 'ios']]]);

    $answer = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->getJson('/chatbot/site/theme?bot=demo')
        ->assertOk()
        ->json();

    expect($answer['properties']['--chatbot-accent'])->toBe('#0ea5e9')
        ->and($answer['flags']['bubbles'])->toBe('ios');

    $this->withHeaders(['Origin' => 'https://elsewhere.example.com'])
        ->getJson('/chatbot/site/theme?bot=demo')
        ->assertForbidden();
});

/**
 * A bot the site may not use still gets an answer, and it is the default one.
 *
 * Refusing here would leave the visitor looking at an unstyled box in the corner
 * of the page while the endpoint that actually carries the conversation explains
 * the real problem. One confusing failure is better than two.
 */
it('answers with the package defaults for a bot it will not talk about', function (): void {
    $this->bot->update([
        'is_public_widget' => false,
        'settings' => ['widget' => ['accent' => '#0ea5e9']],
    ]);

    $answer = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->getJson('/chatbot/site/theme?bot=demo')
        ->assertOk()
        ->json();

    expect($answer['properties']['--chatbot-accent'])->toBe('#4f46e5')
        ->and($answer['flags']['bubbles'])->toBe('rounded');
});

it('will not answer for a bot that is not published to the site', function (): void {
    $this->bot->update(['is_public_widget' => false]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello there'])
        ->assertOk();

    expect(Conversation::query()->whereNotNull('bot_id')->count())->toBe(0);
});

/**
 * A bot may name the sites it belongs on, and then it belongs on those only.
 *
 * This narrows the installation-wide allow-list rather than replacing it: that
 * list is the guard that fails closed, and this one says which of those sites
 * carry this particular bot. One application serving a shop and a helpdesk from
 * one panel is the ordinary case, and without this the shop's bot answers on the
 * helpdesk's pages to anybody who copies the script tag across.
 */
it('keeps a bot to the sites it names, when it names any', function (): void {
    config(['chatbot.widget.origins' => ['https://shop.example.com', 'https://help.example.com']]);
    $this->bot->update(['allowed_origins' => ['https://shop.example.com']]);

    $this->withHeaders(['Origin' => 'https://help.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello there'])
        ->assertOk();

    expect(Conversation::query()->whereNotNull('bot_id')->count())->toBe(0);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello there'])
        ->assertOk();

    expect(Conversation::query()->whereNotNull('bot_id')->count())->toBe(1);
});

/**
 * An empty list means "no narrowing", never "nobody".
 *
 * Every bot has an empty list until somebody fills one in, so reading it the
 * other way would silence every widget in every installation on the upgrade
 * that introduced the check.
 */
it('treats a bot that names no sites as belonging on all the allowed ones', function (): void {
    $this->bot->update(['allowed_origins' => []]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/chatbot/site/messages', ['bot' => 'demo', 'body' => 'hello there'])
        ->assertOk();

    expect(Conversation::query()->whereNotNull('bot_id')->count())->toBe(1);
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
