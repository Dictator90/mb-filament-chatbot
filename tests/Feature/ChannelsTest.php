<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MB\FilamentChatbot\Channels\Drivers\TelegramDriver;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Jobs\DeliverMessage;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use MB\FilamentChatbot\Tests\Support\RecordingChannel;

/**
 * A scenario that answers anything with one sentence.
 *
 * @param  list<array<string, mixed>>  $steps
 */
function channelScenario(array $steps): Scenario
{
    $scenario = Scenario::factory()->create(['bot_id' => null]);

    foreach ($steps as $index => $step) {
        Step::factory()->create([
            'scenario_id' => $scenario->getKey(),
            'sort' => $index,
        ] + $step);
    }

    return $scenario->load('steps');
}

function answeringScenario(): Scenario
{
    return channelScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'reply', 'config' => ['trigger' => 'fallback']],
        ['key' => 'reply', 'type' => 'say', 'body' => 'We are open until six.'],
    ]);
}

function telegramUpdate(int $messageId = 500, string $text = 'when do you close?'): array
{
    return [
        'update_id' => 1,
        'message' => [
            'message_id' => $messageId,
            'chat' => ['id' => 909090],
            'from' => ['first_name' => 'Nina', 'username' => 'nina'],
            'text' => $text,
        ],
    ];
}

/**
 * The Telegram account these tests speak through, and the address it is
 * delivered to.
 *
 * One connection rather than config, because that is now where a token lives:
 * the address carries the account, and a webhook posted without one is a
 * stranger knocking at a door that does not exist.
 */
function telegramConnection(array $credentials = ['token' => 'bot-token', 'secret' => 'shared-secret']): ChannelConnection
{
    return ChannelConnection::factory()->on('telegram', $credentials)->create();
}

function telegramWebhook(ChannelConnection $connection): string
{
    return '/chatbot/webhook/telegram/'.$connection->public_id;
}

beforeEach(function (): void {
    config()->set('chatbot.channels.telegram.enabled', true);
    config()->set('chatbot.channels.telegram.token', 'bot-token');
    config()->set('chatbot.channels.telegram.secret_token', 'shared-secret');
});

/**
 * The failure this prevents costs the customer three answers to one question.
 *
 * Telegram redelivers an update it did not get a 200 for, on a schedule, and
 * "did not get a 200 in time" is the normal state of an application whose bot
 * thinks for a second. Without the guard each redelivery is a fresh incoming
 * message and a fresh bot turn — and nothing anywhere says so, because every
 * one of them is individually correct.
 */
it('does not answer twice when a webhook redelivers the same message', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

    answeringScenario();

    $update = telegramUpdate();
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'];
    $webhook = telegramWebhook(telegramConnection());

    $this->postJson($webhook, $update, $headers)->assertNoContent();
    $this->postJson($webhook, $update, $headers)->assertNoContent();

    $conversation = Conversation::query()->where('channel', 'telegram')->sole();

    expect($conversation->messages()->where('direction', MessageDirection::Incoming)->count())->toBe(1)
        ->and($conversation->messages()->where('direction', MessageDirection::Outgoing)->pluck('body')->all())
        ->toBe(['We are open until six.']);
});

/**
 * Channels re-send whatever they happen to know on every update, and it is
 * routinely less than the row already holds. An operator who corrected a name
 * would watch the provider's version replace it on the customer's next line,
 * with nothing to say it had happened.
 */
it('fills a blank contact field but never overwrites one that is known', function (): void {
    $conversation = Conversation::factory()->create([
        'channel' => 'telegram',
        'external_id' => '4242',
        'display_name' => 'Anna Petrova',
        'contact_phone' => null,
    ]);

    app(MessageRouter::class)->receive(new InboundMessage(
        channel: 'telegram',
        externalConversationId: '4242',
        externalMessageId: '1',
        body: 'hello',
        displayName: 'nina_the_account',
        phone: '+7 900 000 00 00',
    ));

    expect($conversation->refresh()->display_name)->toBe('Anna Petrova')
        ->and($conversation->contact_phone)->toBe('+7 900 000 00 00');
});

/**
 * Delivery must not happen in the request that produced the message.
 *
 * Queue::assertPushed alone proves nothing here — it passes just as happily for
 * a job dispatched synchronously — so the real assertions are that nothing left
 * the building and that the row is still undelivered. Sent inline, a provider
 * taking ten seconds makes the visitor's own message take ten seconds to show.
 */
it('queues delivery of an outgoing message instead of sending it in the request', function (): void {
    Queue::fake();
    Http::fake();

    $conversation = Conversation::factory()->create(['channel' => 'telegram', 'external_id' => '77']);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'On its way.',
    ]);

    Http::assertNothingSent();
    Queue::assertPushed(DeliverMessage::class);

    expect($message->refresh()->delivered_at)->toBeNull()
        ->and($message->failed_at)->toBeNull();
});

/**
 * Nothing is queued for a channel the reader comes to.
 *
 * The site widget polls this application, so a reply is already where it needs
 * to be the moment the row exists, and the job that would "send" it has nothing
 * to do but write down that it did. That is not merely wasteful: on an
 * installation whose worker is not running, those no-op jobs become the queue —
 * a backlog made entirely of work nobody needed, hiding the jobs that matter.
 * The first real installation this package met had thirty-seven of them and not
 * one that did anything.
 *
 * Telegram is here in the same test on purpose. Alone, the site half would pass
 * just as happily if the outbox had stopped queueing altogether.
 */
it('queues nothing for a channel the reader comes to, and still queues for one that leaves', function (): void {
    Queue::fake();

    $site = Conversation::factory()->create(['channel' => 'site']);
    $telegram = Conversation::factory()->create(['channel' => 'telegram', 'external_id' => '77']);

    Message::create([
        'conversation_id' => $site->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Waiting to be fetched.',
    ]);

    Queue::assertNotPushed(DeliverMessage::class);

    Message::create([
        'conversation_id' => $telegram->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'On its way.',
    ]);

    Queue::assertPushed(DeliverMessage::class, 1);
});

/**
 * A switched-off channel is still queued, and that is the point.
 *
 * Skipping the queue says how a channel delivers, not whether it can. A site
 * channel somebody has turned off delivers nothing at all, and the only place
 * that says so is the reason the job writes on the row; skip it and the message
 * sits pending forever, looking exactly like a customer who has not replied.
 */
it('still queues for a channel that is switched off, so the row can say why', function (): void {
    Queue::fake();
    config()->set('chatbot.channels.site.enabled', false);

    $conversation = Conversation::factory()->create(['channel' => 'site']);

    Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Nowhere to go.',
    ]);

    Queue::assertPushed(DeliverMessage::class, 1);
});

/**
 * A message with nowhere to go must be visibly broken, not quietly pending.
 *
 * Left unmarked it looks exactly like a message the customer is slow to answer,
 * and the channel is missing precisely when somebody has switched it off,
 * removed the package or misspelled the key — all three fixed by reading the
 * reason, none of them noticed without it.
 */
it('fails a message whose channel has no driver, naming the channel', function (): void {
    $conversation = Conversation::factory()->create(['channel' => 'carrier-pigeon']);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Anybody there?',
    ]);

    $message->refresh();

    expect($message->delivered_at)->toBeNull()
        ->and($message->failed_at)->not->toBeNull()
        ->and($message->failure_reason)->toContain('carrier-pigeon');
});

/**
 * A channel that draws no buttons has to carry the options in the text, or the
 * question it just asked cannot be answered at all.
 */
it('lists the options as a numbered list on a channel without buttons', function (): void {
    $driver = new RecordingChannel('plain', buttons: false);
    app(ChatbotRegistry::class)->registerChannel($driver);

    $conversation = Conversation::factory()->create(['channel' => 'plain']);

    Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Sales or support?',
        'meta' => ['options' => [['label' => 'Sales'], ['label' => 'Support']]],
    ]);

    expect($driver->sent)->toBe(["Sales or support?\n\n1. Sales\n2. Support"]);
});

/**
 * The webhook URL is public, so the secret header is the whole of its
 * authentication. Without the check, anyone who learns the path can post
 * messages into the desk as any chat id they choose.
 */
it('refuses a telegram webhook without the right secret', function (): void {
    $update = telegramUpdate();
    $webhook = telegramWebhook(telegramConnection());

    $this->postJson($webhook, $update)->assertForbidden();
    $this->postJson($webhook, $update, [
        'X-Telegram-Bot-Api-Secret-Token' => 'guessed',
    ])->assertForbidden();

    expect(Conversation::query()->count())->toBe(0);
});

/**
 * A connection with no secret saved accepts nothing, rather than everything:
 * a half-finished setup must not be an open door into the desk.
 */
it('refuses every telegram webhook while no secret is configured', function (): void {
    $connection = telegramConnection(['token' => 'bot-token']);

    $this->postJson(telegramWebhook($connection), telegramUpdate(), [
        'X-Telegram-Bot-Api-Secret-Token' => '',
    ])->assertForbidden();

    expect(Conversation::query()->count())->toBe(0);
});

it('sends through the telegram bot api and keeps the id it returns', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]])]);

    $conversation = Conversation::factory()->create(['channel' => 'telegram', 'external_id' => '55']);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Pick one',
        'meta' => ['options' => ['Yes', 'No']],
    ]);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/botbot-token/sendMessage')
            && $request['chat_id'] === '55'
            && $request['text'] === 'Pick one'
            && $request['reply_markup']['inline_keyboard'][1][0]['text'] === 'No';
    });

    expect($message->refresh()->delivered_at)->not->toBeNull()
        ->and($message->external_id)->toBe('4242');
});

it('reports telegram as unconfigured while it has no token', function (): void {
    config()->set('chatbot.channels.telegram.token', '');

    expect(app(TelegramDriver::class)->isConfigured())->toBeFalse();
});
