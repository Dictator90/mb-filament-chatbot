<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Channels\ChannelHttp;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * An outgoing message on a thread that belongs to a connection.
 *
 * Written through the model so that the observer queues delivery exactly as it
 * does in the application: a test that called the driver directly would pass
 * with the outbox wired to nothing.
 *
 * @param  list<string>  $options
 */
function outgoing(ChannelConnection $connection, string $externalId, string $body, array $options = []): Message
{
    $conversation = Conversation::factory()->create([
        'bot_id' => $connection->bot_id,
        'channel' => $connection->channel,
        'external_id' => $externalId,
    ]);

    return Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => $body,
        'meta' => $options === [] ? [] : ['options' => $options],
    ]);
}

function webhookFor(ChannelConnection $connection): string
{
    return '/chatbot/webhook/'.$connection->channel.'/'.$connection->public_id;
}

/**
 * The reason connections exist at all.
 *
 * Two bots on one installation, each with its own Telegram account: the update
 * says nothing about which bot it belongs to, so the address has to. With a
 * single installation-wide token this test could not even be written — the
 * second bot had nowhere to keep its own.
 */
it('routes an update to the bot whose address it arrived at', function (): void {
    $sales = Bot::factory()->create(['slug' => 'sales']);
    $support = Bot::factory()->create(['slug' => 'support']);

    ChannelConnection::factory()->for($sales)->create();
    $supportLine = ChannelConnection::factory()->for($support)->create([
        'credentials' => ['token' => 'support-token', 'secret' => 'support-secret'],
    ]);

    $this->postJson(webhookFor($supportLine), [
        'update_id' => 1,
        'message' => [
            'message_id' => 11,
            'chat' => ['id' => 500],
            'from' => ['first_name' => 'Nina'],
            'text' => 'are you open?',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'support-secret'])->assertNoContent();

    $conversation = Conversation::query()->sole();

    expect($conversation->bot_id)->toBe($support->getKey())
        ->and($conversation->channel)->toBe('telegram');
});

/**
 * The proxy is the account's, and the installation's only when the account has
 * none.
 *
 * The case it exists for is a host that cannot reach a provider at all: the
 * call fails as a ten-second silence, which on screen reads as a fault in this
 * package rather than as a blocked route. Two bots can sit behind different
 * routes, so the connection wins.
 */
it('takes the proxy from the connection, and from config only without one', function (): void {
    config()->set('chatbot.channels.telegram.proxy', 'socks5://installation:1080');

    $shared = ChannelConnection::factory()->create();
    $own = ChannelConnection::factory()->create([
        'credentials' => ['token' => 't', 'secret' => 's', 'proxy' => 'socks5://account:1080'],
    ]);

    expect(ChannelHttp::proxyFor('telegram', $own))->toBe('socks5://account:1080')
        ->and(ChannelHttp::proxyFor('telegram', $shared))->toBe('socks5://installation:1080');

    config()->set('chatbot.channels.telegram.proxy', null);

    expect(ChannelHttp::proxyFor('telegram', $shared))->toBe('');
});

/**
 * An address nobody connected is a 404, and so is one that was switched off.
 *
 * The same answer for both on purpose: a different one would tell somebody who
 * guessed an address that they had guessed a real one.
 */
it('answers nothing at an address that is unknown or switched off', function (): void {
    $this->postJson('/chatbot/webhook/telegram/'.str_repeat('a', 32), [])->assertNotFound();

    $connection = ChannelConnection::factory()->inactive()->create();

    $this->postJson(webhookFor($connection), [], [
        'X-Telegram-Bot-Api-Secret-Token' => $connection->credential('secret'),
    ])->assertNotFound();

    expect(Conversation::query()->count())->toBe(0);
});

/**
 * MAX authenticates with its own header, and the thread is keyed by the chat
 * rather than by the sender: a dialog is a chat of two, and keying by sender
 * would open a second thread the moment the same person wrote from a group.
 */
it('takes a max message in and refuses one without the secret', function (): void {
    $connection = ChannelConnection::factory()->on('max', [
        'token' => 'max-token',
        'secret' => 'max-secret',
    ])->create();

    $update = [
        'update_type' => 'message_created',
        'message' => [
            'sender' => ['user_id' => 77, 'name' => 'Nina', 'username' => 'nina'],
            'recipient' => ['chat_id' => 909],
            'body' => ['mid' => 'mid-1', 'text' => 'when do you close?'],
        ],
    ];

    $this->postJson(webhookFor($connection), $update)->assertForbidden();

    $this->postJson(webhookFor($connection), $update, ['X-Max-Bot-Api-Secret' => 'max-secret'])
        ->assertNoContent();

    $conversation = Conversation::query()->sole();

    expect($conversation->channel)->toBe('max')
        ->and($conversation->external_id)->toBe('909')
        ->and($conversation->bot_id)->toBe($connection->bot_id)
        ->and($conversation->messages()->value('body'))->toBe('when do you close?');
});

/**
 * The token goes in a header and the recipient in the query string — the two
 * places MAX differs from Telegram in a way that fails silently if copied.
 */
it('sends to max with the token in a header and options as a keyboard', function (): void {
    Http::fake(['platform-api2.max.ru/*' => Http::response(['message' => ['body' => ['mid' => 'mid-9']]])]);

    $connection = ChannelConnection::factory()->on('max', ['token' => 'max-token', 'secret' => 'max-secret'])->create();

    outgoing($connection, '909', 'Sales or support?', ['Sales', 'Support']);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return str_contains($request->url(), 'chat_id=909')
            && $request->header('Authorization')[0] === 'max-token'
            && $payload['attachments'][0]['type'] === 'inline_keyboard'
            && $payload['attachments'][0]['payload']['buttons'][1][0]['payload'] === '2';
    });
});

/**
 * VK confirms a new address through the same endpoint the events arrive at,
 * and the body it wants back is a bare string. A JSON `"ok"` is not `ok`, and
 * the community pauses delivery over it.
 */
it('confirms a vk address and answers events with ok', function (): void {
    $connection = ChannelConnection::factory()->on('vk', [
        'token' => 'community-token',
        'secret' => 'vk-secret',
        'confirmation' => 'abc123',
    ])->create();

    /*
     * The exact body, not a substring of it. VK compares what comes back, so
     * a JSON envelope that happens to contain the word is a failure it keeps
     * retrying — and `assertSee` would call that a pass.
     */
    $this->postJson(webhookFor($connection), [
        'type' => 'confirmation',
        'group_id' => 42,
        'secret' => 'vk-secret',
    ])->assertOk()->assertContent('abc123');

    $this->postJson(webhookFor($connection), [
        'type' => 'message_new',
        'secret' => 'vk-secret',
        'object' => ['message' => ['id' => 5, 'peer_id' => 707, 'text' => 'hello']],
    ])->assertOk()->assertContent('ok');

    $conversation = Conversation::query()->sole();

    expect($conversation->channel)->toBe('vk')
        ->and($conversation->external_id)->toBe('707')
        ->and($conversation->messages()->value('body'))->toBe('hello');
});

/**
 * A press on an inline keyboard arrives as an ordinary message whose text is
 * the label. The payload beside it carries the position, and the position wins:
 * two options can share a label, and the engine resolves a digit exactly.
 */
it('reads a vk button press as the option number', function (): void {
    $connection = ChannelConnection::factory()->on('vk', [
        'token' => 'community-token',
        'secret' => 'vk-secret',
        'confirmation' => 'abc123',
    ])->create();

    $this->postJson(webhookFor($connection), [
        'type' => 'message_new',
        'secret' => 'vk-secret',
        'object' => ['message' => [
            'id' => 6,
            'peer_id' => 707,
            'text' => 'Support',
            'payload' => json_encode(['option' => 2]),
        ]],
    ])->assertOk();

    expect(Conversation::query()->sole()->messages()->value('body'))->toBe('2');
});

/**
 * Meta verifies an address by fetching it, before any secret exists to check —
 * and the challenge is echoed only when the verify token matches, or anybody
 * who guessed the address could point their own app at it.
 */
it('answers the whatsapp verification only with the right token', function (): void {
    $connection = ChannelConnection::factory()->on('whatsapp', [
        'token' => 'meta-token',
        'phone_number_id' => '1234',
        'verify_token' => 'verify-me',
        'app_secret' => 'app-secret',
    ])->create();

    $this->get(webhookFor($connection).'?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=55555')
        ->assertOk()
        ->assertSee('55555');

    $this->get(webhookFor($connection).'?hub_mode=subscribe&hub_verify_token=guessed&hub_challenge=55555')
        ->assertForbidden();
});

/**
 * The signature is over the bytes Meta sent, not over a re-encoded array —
 * the usual way this check passes in tests and fails in production.
 */
it('takes a whatsapp message in only with a valid signature', function (): void {
    $connection = ChannelConnection::factory()->on('whatsapp', [
        'token' => 'meta-token',
        'phone_number_id' => '1234',
        'verify_token' => 'verify-me',
        'app_secret' => 'app-secret',
    ])->create();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Nina'], 'wa_id' => '79990000000']],
                    'messages' => [[
                        'from' => '79990000000',
                        'id' => 'wamid.1',
                        'type' => 'text',
                        'text' => ['body' => 'do you ship?'],
                    ]],
                ],
            ]],
        ]],
    ];

    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', (string) $body, 'app-secret');

    $this->call('POST', webhookFor($connection), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong',
    ], (string) $body)->assertForbidden();

    $this->call('POST', webhookFor($connection), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], (string) $body)->assertNoContent();

    $conversation = Conversation::query()->sole();

    expect($conversation->channel)->toBe('whatsapp')
        ->and($conversation->external_id)->toBe('79990000000')
        ->and($conversation->contact_phone)->toBe('+79990000000')
        ->and($conversation->messages()->value('body'))->toBe('do you ship?');
});

/**
 * Three short options are buttons; a fourth, or a long one, is a numbered list.
 *
 * WhatsApp refuses the whole message over a fourth button or a title past
 * twenty characters, so the question would arrive with no way to answer it.
 */
it('uses whatsapp buttons only where they fit and falls back to a list', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.9']]])]);

    $connection = ChannelConnection::factory()->on('whatsapp', [
        'token' => 'meta-token',
        'phone_number_id' => '1234',
        'verify_token' => 'verify-me',
        'app_secret' => 'app-secret',
    ])->create();

    outgoing($connection, '79990000000', 'Sales or support?', ['Sales', 'Support']);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $payload['type'] === 'interactive'
            && $payload['interactive']['action']['buttons'][1]['reply']['id'] === '2';
    });

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.10']]])]);

    outgoing($connection, '79990000001', 'Which one?', ['One', 'Two', 'Three', 'Four']);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $payload['type'] === 'text'
            && str_contains((string) $payload['text']['body'], '4. Four');
    });
});
