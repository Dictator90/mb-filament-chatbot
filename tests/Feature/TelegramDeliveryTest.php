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

/**
 * Нажатая кнопка остаётся видимой: ответ вписывается в сам вопрос.
 *
 * Нажатие inline-кнопки не оставляет в чате ничего — клавиатура исчезает,
 * и человек смотрит на вопрос без ответа под ним. На анкете из дюжины
 * вопросов так пропадает почти весь разговор: спроси его, что он выбрал, —
 * не скажет. Писать сообщение от имени посетителя Telegram боту не даёт,
 * поэтому единственное место, где можно показать выбор, — сам вопрос.
 */
it('вписывает выбранный вариант в вопрос, с которого его нажали', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb-10',
            'from' => ['first_name' => 'Нина'],
            'message' => [
                'message_id' => 405,
                'chat' => ['id' => 500],
                'text' => 'Что Вам ближе: море и отдых, экскурсии или сочетание?',
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => 'Пляжный', 'callback_data' => '1']],
                    [['text' => 'Экскурсионный', 'callback_data' => '2']],
                ]],
            ],
            'data' => '2',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/editMessageText')
        && ($request->data()['message_id'] ?? null) === 405
        && str_contains((string) ($request->data()['text'] ?? ''), 'Что Вам ближе')
        && str_contains((string) ($request->data()['text'] ?? ''), '— Экскурсионный')
        && ($request->data()['reply_markup']['inline_keyboard'] ?? null) === []);
});

/**
 * И в переписке оператора лежит слово, а не номер кнопки.
 *
 * Данные кнопки не вмещают подписи, поэтому они несут позицию — и позиция
 * же попадала в стол: открыв разговор, оператор читал «2». Подпись Telegram
 * присылает обратно вместе с нажатием, в той самой клавиатуре.
 */
it('записывает в переписку подпись кнопки, а не её номер', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 2,
        'callback_query' => [
            'id' => 'cb-11',
            'from' => ['first_name' => 'Нина'],
            'message' => [
                'message_id' => 406,
                'chat' => ['id' => 501],
                'text' => 'Какой уровень отеля рассматриваете?',
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '3★', 'callback_data' => '1']],
                    [['text' => '4★', 'callback_data' => '2']],
                ]],
            ],
            'data' => '2',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    expect(Message::query()->where('external_id', 'callback:cb-11')->sole()->body)->toBe('4★');
});

/** Без клавиатуры в обновлении остаётся прежнее поведение: номер и снятие кнопок. */
it('не выдумывает подпись, когда клавиатуры в нажатии нет', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 3,
        'callback_query' => [
            'id' => 'cb-12',
            'from' => ['first_name' => 'Нина'],
            'message' => ['message_id' => 407, 'chat' => ['id' => 502]],
            'data' => '2',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    expect(Message::query()->where('external_id', 'callback:cb-12')->sole()->body)->toBe('2');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/editMessageReplyMarkup')
        && ($request->data()['message_id'] ?? null) === 407);
});

/**
 * Контакт, отданный кнопкой, — единственный телефон, которому бот верит.
 *
 * `contact.user_id` заполняет сам Telegram, и заполняет аккаунтом, которому
 * карточка принадлежит: совпал с отправителем — номер его собственный.
 * Набранный в сообщении номер не доказывает ничего, и привязка по нему
 * отдала бы чужие брони любому, кто знает чужой телефон.
 */
it('верит номеру, который Telegram отдал карточкой своего аккаунта', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 10,
        'message' => [
            'message_id' => 500,
            'chat' => ['id' => 600],
            'from' => ['id' => 42, 'first_name' => 'Нина'],
            'contact' => ['phone_number' => '+79990001122', 'first_name' => 'Нина', 'user_id' => 42],
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    $conversation = Conversation::query()->where('external_id', '600')->sole();

    expect($conversation->contact_phone)->toBe('+79990001122')
        ->and($conversation->identified_at)->not->toBeNull();
});

/**
 * А пересланная чужая карточка — не доказательство: `user_id` в ней чужой
 * или его нет вовсе. Разговор продолжается, номер остаётся словами.
 */
it('не верит пересланной чужой карточке', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 11,
        'message' => [
            'message_id' => 501,
            'chat' => ['id' => 601],
            'from' => ['id' => 42, 'first_name' => 'Нина'],
            'contact' => ['phone_number' => '+79994445566', 'first_name' => 'Пётр', 'user_id' => 77],
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    $conversation = Conversation::query()->where('external_id', '601')->sole();

    expect($conversation->contact_phone)->toBeNull()
        ->and($conversation->identified_at)->toBeNull()
        ->and(Message::query()->where('conversation_id', $conversation->getKey())->where('direction', 'in')->sole()->body)
        ->toBe('+79994445566');
});

/**
 * Подтверждённый номер лежит отдельно от названного словами.
 *
 * Порядок, которого никто не планирует: человек набрал номер руками (ответ
 * анкеты попал в `contact_phone`), и только потом нажал кнопку. Колонка
 * занята, занятую колонка не перезаписывает, и отметка о подтверждении
 * оказалась бы рядом с цифрами, которых никто не проверял. Хозяин читает
 * `proven('phone')`, и там всегда карточка аккаунта.
 */
it('держит подтверждённый номер отдельно от набранного руками', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $conversation = Conversation::factory()->create([
        'bot_id' => $connection->bot_id,
        'channel' => 'telegram',
        'external_id' => '650',
        'contact_phone' => '+79995554433',
    ]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 12,
        'message' => [
            'message_id' => 502,
            'chat' => ['id' => 650],
            'from' => ['id' => 42, 'first_name' => 'Нина'],
            'contact' => ['phone_number' => '+79990001122', 'first_name' => 'Нина', 'user_id' => 42],
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    $conversation->refresh();

    expect($conversation->contact_phone)->toBe('+79995554433')
        ->and($conversation->identified_at)->not->toBeNull()
        ->and($conversation->proven('phone'))->toBe('+79990001122')
        ->and($conversation->proven('email'))->toBeNull();
});

/**
 * Слова не попадают в подтверждённые приметы никогда — ни первым сообщением,
 * ни после того, как разговор уже признан подтверждённым.
 */
it('не записывает названный словами номер в подтверждённые', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $conversation = Conversation::factory()->create([
        'bot_id' => $connection->bot_id,
        'channel' => 'telegram',
        'external_id' => '651',
        'identified_at' => now(),
        'contact_phone' => '+79995554433',
    ]);

    $this->postJson('/chatbot/webhook/telegram/'.$connection->public_id, [
        'update_id' => 13,
        'message' => [
            'message_id' => 503,
            'chat' => ['id' => 651],
            'from' => ['id' => 42, 'first_name' => 'Нина'],
            'text' => 'мой телефон +79995554433',
        ],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shared-secret'])->assertNoContent();

    expect($conversation->refresh()->proven('phone'))->toBeNull();
});

/**
 * Вопрос о телефоне приходит с кнопкой, которой контакт и отдают.
 *
 * Inline-кнопка так не умеет: карточку отдаёт только обычная клавиатура
 * под полем ввода. Без неё «отправьте телефон» означает «наберите его
 * руками», то есть ровно тот ответ, которому верить нельзя.
 */
it('просит телефон кнопкой «отправить мой номер»', function (): void {
    $connection = ChannelConnection::factory()->create([
        'credentials' => ['token' => 'bot-token', 'secret' => 'shared-secret'],
    ]);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $conversation = Conversation::factory()->create([
        'bot_id' => $connection->bot_id,
        'channel' => 'telegram',
        'external_id' => '700',
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => 'out',
        'author' => 'bot',
        'body' => 'Оставьте, пожалуйста, номер телефона — менеджер перезвонит.',
        'meta' => ['request_contact' => true],
    ]);

    app(TelegramDriver::class)->send($conversation, $message);

    Http::assertSent(function ($request): bool {
        $markup = $request->data()['reply_markup'] ?? [];

        return str_contains($request->url(), '/sendMessage')
            && ($markup['keyboard'][0][0]['request_contact'] ?? false) === true
            && ($markup['one_time_keyboard'] ?? false) === true;
    });
});
