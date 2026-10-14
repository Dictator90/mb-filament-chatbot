<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Channels\Drivers\TelegramDriver;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * An outgoing message with a file on it, filed the way the desk files one.
 */
function withFile(ChannelConnection $connection, string $name, string $mime, string $body = ''): Message
{
    Storage::fake('local');

    /*
     * The observer queues delivery the moment the row exists, and the desk
     * attaches the file a moment later. Letting that job run here would send
     * the message before it had a file on it — which is the queue's ordering,
     * not this driver's behaviour, and it would make every assertion below
     * about the wrong send.
     */
    Queue::fake();

    $conversation = Conversation::factory()->create([
        'bot_id' => $connection->bot_id,
        'channel' => 'telegram',
        'external_id' => '500',
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Operator,
        'body' => $body === '' ? $name : $body,
    ]);

    Storage::disk('local')->put('chatbot/attachments/'.$name, 'bytes');

    Attachment::create([
        'uuid' => (string) Str::uuid(),
        'message_id' => $message->getKey(),
        'conversation_id' => $conversation->getKey(),
        'disk' => 'local',
        'path' => 'chatbot/attachments/'.$name,
        'name' => $name,
        'mime' => $mime,
        'size' => 5,
    ]);

    return $message->refresh();
}

function deliver(Message $message): void
{
    app(TelegramDriver::class)->send($message->conversation, $message);
}

/**
 * A file is sent as a file, not as a line of text with its name on it.
 *
 * What happened instead: an operator attached a photograph from the desk and
 * the customer received `XXXL.webp` as a message. The transcript said the file
 * had been sent and the operator believed it; only the customer knew there was
 * nothing to open.
 *
 * A picture goes as a photo rather than as a document because Telegram renders
 * only the first in place, and a photograph arriving as a download is a file
 * nobody opens on a phone.
 */
it('sends an attached picture as a photo', function (): void {
    $connection = ChannelConnection::factory()->create();

    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

    deliver(withFile($connection, 'hotel.jpg', 'image/jpeg'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/sendMessage'));
});

/**
 * Anything that is not a picture goes as a document, with its own name.
 */
it('sends an attached document as a document', function (): void {
    $connection = ChannelConnection::factory()->create();

    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 8]])]);

    deliver(withFile($connection, 'contract.pdf', 'application/pdf'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendDocument'));
});

/**
 * Text longer than a caption is sent on its own, before the file.
 *
 * Telegram caps a caption at 1024 characters and refuses the request over it.
 * Trimming to fit would lose the end of an operator's explanation silently,
 * which is worse than a second message.
 */
it('sends a long explanation separately from the file', function (): void {
    $connection = ChannelConnection::factory()->create();

    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);

    deliver(withFile($connection, 'hotel.jpg', 'image/jpeg', str_repeat('а', 1100)));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto'));
});

/**
 * A pressed button loses its keyboard, and the spinner stops.
 *
 * A keyboard in Telegram never expires: it stays under the message for ever and
 * answers with the option's position in a question the conversation left behind
 * hours ago. The visitor presses "call a person" on yesterday's menu and the
 * bot replies that it cannot find that in the knowledge base, because position
 * two now means something else. This is what makes a tapped answer mean what it
 * said when it was tapped.
 */
it('takes the keyboard away once a button has been pressed', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb-9',
            'from' => ['first_name' => 'Nina'],
            'message' => ['message_id' => 404, 'chat' => ['id' => 500]],
            'data' => '2',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/answerCallbackQuery')
        && ($request->data()['callback_query_id'] ?? null) === 'cb-9');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/editMessageReplyMarkup')
        && ($request->data()['message_id'] ?? null) === 404
        && ($request->data()['reply_markup']['inline_keyboard'] ?? null) === []);
});
