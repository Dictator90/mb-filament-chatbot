<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use MB\FilamentChatbot\Channels\ChannelConnections;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\ChannelHttp;
use MB\FilamentChatbot\Channels\ChannelPolling;
use MB\FilamentChatbot\Channels\ChannelSetup;
use MB\FilamentChatbot\Channels\ChannelStatus;
use MB\FilamentChatbot\Channels\ChannelText;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\PollBatch;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram, over the Bot API directly.
 *
 * **Deliberately without a Telegram SDK.** What this driver needs is one POST
 * to sendMessage and one shape of JSON back; every package that wraps it also
 * brings its own migrations, its own models for bots and chats, and its own
 * opinion about where a conversation lives — all of which this package already
 * has, differently. Installing one would mean maintaining a translation between
 * two conversation tables forever, in exchange for a helper around a single
 * HTTP call.
 *
 * **The token comes from the connection, not from config.** One installation
 * runs several bots, and a Telegram account belongs to one of them; config can
 * hold only a single token, so the second bot had nowhere to keep its own. A
 * config token is still honoured when a conversation has no connection behind
 * it, so an installation set up before connections existed keeps answering
 * until it is moved over.
 */
final class TelegramDriver implements ChannelDriver, ChannelPolling, ChannelSetup
{
    public const KEY = 'telegram';

    /**
     * Telegram refuses a message longer than this, and a refused edit would
     * leave the question standing with its buttons and no answer under it.
     */
    private const TEXT_LIMIT = 4096;

    public function __construct(
        private readonly ChannelConnections $connections,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Telegram';
    }

    /**
     * Whether anything on this installation could send a Telegram message.
     *
     * An installation-wide answer to an installation-wide question; the
     * settings screen asks about one account at a time through {@see verify()}.
     * Reported rather than thrown so that an installation which has not been
     * given a bot yet still boots, still shows Telegram on the settings screen,
     * and fails one message with a readable reason instead of every request with
     * a stack trace.
     */
    public function isConfigured(): bool
    {
        return $this->configToken() !== ''
            || ChannelConnection::query()->where('channel', self::KEY)->active()->exists();
    }

    /**
     * @return array<string, array{label: string, secret?: bool, hint?: string, required?: bool}>
     */
    public function credentialFields(): array
    {
        return [
            'token' => [
                'label' => 'Bot token',
                'secret' => true,
                'required' => true,
                'hint' => 'From @BotFather, in the form 123456:ABC-DEF.',
            ],
            'secret' => [
                'label' => 'Webhook secret',
                'secret' => true,
                'required' => true,
                'hint' => 'Any random string. Telegram returns it with every update, and it is the whole of what tells Telegram apart from a stranger who found the address.',
            ],
            'proxy' => [
                'label' => 'Proxy',
                'secret' => true,
                'hint' => 'Optional, e.g. socks5://127.0.0.1:1080. For networks that cannot reach the Bot API at all — the symptom is a ten-second silence rather than a refusal. Applies only to calls this application makes; the webhook arrives the other way.',
            ],
        ];
    }

    public function verify(ChannelConnection $connection): ChannelStatus
    {
        $token = $connection->credential('token');

        if ($token === '') {
            return ChannelStatus::failed('No bot token.');
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)->get($this->endpoint($token, 'getMe'));
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $username = trim((string) ($body['result']['username'] ?? ''));

            return ChannelStatus::ok($username === '' ? 'Telegram accepted the token.' : '@'.$username);
        }

        return ChannelStatus::failed($this->reason($body, $response->status()));
    }

    /**
     * Registers the address with Telegram, secret included.
     *
     * `drop_pending_updates` is deliberately not sent. Pointing a webhook at a
     * new address usually means a move rather than a fresh start, and the
     * updates waiting in Telegram's queue are customers' messages: dropping
     * them would lose, silently, every question asked during the move.
     */
    public function installWebhook(ChannelConnection $connection, string $url): ChannelStatus
    {
        $token = $connection->credential('token');
        $secret = $connection->credential('secret');

        if ($token === '') {
            return ChannelStatus::failed('No bot token.');
        }

        if ($secret === '') {
            return ChannelStatus::failed('No webhook secret: Telegram would be indistinguishable from anybody else who learns the address.');
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)->asJson()->post($this->endpoint($token, 'setWebhook'), [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ]);
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            return ChannelStatus::ok('Telegram will deliver updates to this address.');
        }

        return ChannelStatus::failed($this->reason($body, $response->status()));
    }

    /**
     * **An unconfigured secret refuses everything.**
     *
     * The tempting reverse — skip the check when no secret is set — would make
     * a forgotten setting into an open endpoint that writes into the customer
     * desk, and it would work perfectly in testing, which is exactly when the
     * setting is most likely to be missing.
     *
     * Compared with hash_equals so that the comparison takes the same time
     * whatever the wrong value was; a plain === leaks the correct prefix to
     * anyone patient enough to measure.
     */
    public function authentic(Request $request, ChannelConnection $connection): bool
    {
        $expected = $connection->credential('secret');

        if ($expected === '') {
            return false;
        }

        $given = trim((string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token', ''));

        return $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Turns one update into an inbound message, or into nothing.
     *
     * Only text messages and button presses are understood. Everything else —
     * a sticker, a member joining, a poll answer — decodes to null, which the
     * router treats as nothing to store: no thread is opened and the bot does
     * not take a turn over a payload it has no words for.
     *
     * A button press is read as the option's number rather than as its label.
     * The keyboard was built with the position in the callback data for a
     * reason (see {@see keyboard()}), and the engine resolves a bare digit
     * against the same ordering.
     */
    public function decode(Request $request, ChannelConnection $connection): ?InboundMessage
    {
        $update = (array) $request->json()->all();
        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $chatId = $this->chatId($callback['message'] ?? null);

            if ($chatId === null) {
                return null;
            }

            $sender = $this->from($callback['from'] ?? null);

            /*
             * The label the person pressed, not the number underneath it.
             *
             * Callback data cannot carry a label — 64 bytes — so the buttons
             * carry positions, and the position was what reached the engine
             * and what the desk recorded. Two people then looked at the same
             * conversation and saw "2": the operator in the transcript, and
             * the visitor in a chat where a pressed inline button leaves no
             * message of its own at all. Telegram sends the keyboard back with
             * the callback, so the word is right here to be read.
             */
            $pressed = $this->pressedLabel($callback);

            $this->closeKeyboard($connection, $callback, $chatId, $pressed);

            return new InboundMessage(
                channel: self::KEY,
                externalConversationId: $chatId,
                /*
                 * Prefixed, because a callback id and a message id are numbered
                 * in separate sequences and would otherwise collide — one
                 * button press silently swallowed as a redelivery of an
                 * unrelated message the visitor sent earlier.
                 */
                externalMessageId: 'callback:'.(string) ($callback['id'] ?? ''),
                body: $pressed ?? trim((string) ($callback['data'] ?? '')),
                displayName: $sender['displayName'],
                username: $sender['username'],
                botId: $connection->bot_id,
            );
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $chatId = $this->chatId($message);
        $sender = $this->from($message['from'] ?? null);

        /*
         * A shared contact is the one phone number in this package that is
         * proof rather than a claim.
         *
         * Telegram fills `contact.user_id` itself, and it fills it with the
         * account the card belongs to — so a card whose `user_id` equals the
         * sender is that sender's own number, confirmed by Telegram and not
         * typed by anybody. A number read out in a message proves nothing:
         * whoever knows a customer's phone could otherwise be handed their
         * bookings by a bot that believed words.
         *
         * A forwarded card — somebody else's contact — arrives with a
         * different `user_id` or none at all. It is kept as text, so the
         * conversation goes on, and the thread stays unproved.
         */
        $contact = $message['contact'] ?? null;

        if (is_array($contact)) {
            $number = trim((string) ($contact['phone_number'] ?? ''));
            $owner = $contact['user_id'] ?? null;
            $from = $message['from']['id'] ?? null;
            $itsOwn = $number !== '' && $owner !== null && $from !== null && (string) $owner === (string) $from;

            if ($chatId === null || $number === '') {
                return null;
            }

            return new InboundMessage(
                channel: self::KEY,
                externalConversationId: $chatId,
                externalMessageId: (string) ($message['message_id'] ?? ''),
                body: $number,
                displayName: $sender['displayName'],
                username: $sender['username'],
                phone: $itsOwn ? $number : null,
                botId: $connection->bot_id,
                identified: $itsOwn,
            );
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if ($chatId === null || $text === '') {
            return null;
        }

        return new InboundMessage(
            channel: self::KEY,
            externalConversationId: $chatId,
            externalMessageId: (string) ($message['message_id'] ?? ''),
            body: $text,
            displayName: $sender['displayName'],
            username: $sender['username'],
            botId: $connection->bot_id,
        );
    }

    /**
     * 200 for anything that got past the secret, including updates this package
     * makes nothing of. Telegram treats any other status as a failure and
     * redelivers the same update on a schedule, so answering a sticker with an
     * error would produce an endless retry of something there is no version of
     * the code that will ever accept.
     */
    public function acknowledge(Request $request, ChannelConnection $connection): Response
    {
        return response()->noContent();
    }

    /**
     * Sends the text, with the options as an inline keyboard when there are any.
     *
     * The chat id is the conversation's external id, which is what the webhook
     * wrote when the thread was opened. A thread without one cannot be answered
     * at all, and saying so is more useful than a Bot API error about a missing
     * parameter.
     */
    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        $chatId = trim((string) $conversation->external_id);

        if ($chatId === '') {
            return DeliveryResult::failed('The conversation has no Telegram chat id.', retryable: false);
        }

        $connection = $this->connections->forConversation($conversation);
        $token = $connection?->credential('token') ?: $this->configToken();

        if ($token === '') {
            return DeliveryResult::failed('This bot has no Telegram account connected.', retryable: false);
        }

        $files = $message->attachments()->get();

        if ($files->isNotEmpty()) {
            return $this->sendFiles($connection, $token, $chatId, $message, $files);
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => ChannelText::forDriver($message, $this),
        ];

        $parseMode = $this->parseMode($connection);

        if ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        $keyboard = $this->keyboard($message);

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)
                ->asJson()
                ->post($this->endpoint($token, 'sendMessage'), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $messageId = $body['result']['message_id'] ?? null;

            return DeliveryResult::sent($messageId === null ? null : (string) $messageId);
        }

        /*
         * 4xx other than 429 is Telegram saying the request itself is wrong — a
         * blocked bot, a chat that no longer exists, text over the limit. None of
         * those change by being sent again, and retrying them burns the budget
         * on a verdict that is already final.
         */
        $retryable = $response->status() === 429 || $response->status() >= 500;

        return DeliveryResult::failed($this->reason($body, $response->status()), retryable: $retryable);
    }

    /**
     * Sends the files themselves, not their names.
     *
     * An operator answering from the desk attaches a photograph of a hotel or
     * a contract as a PDF, and the customer used to receive the file *name* as
     * a line of text — `XXXL.webp` — with nothing to open. The transcript said
     * the file had been sent, the operator believed it, and only the customer
     * knew otherwise.
     *
     * A picture goes as a photo and everything else as a document, because
     * Telegram renders only the first in place and a photograph arriving as a
     * download is a file nobody opens on a phone. The text rides along as the
     * first file's caption when it fits — a caption is capped at 1024
     * characters, and over that it is sent as its own message first, so a long
     * explanation is never silently truncated to fit a picture.
     *
     * @param  Collection<int, Attachment>  $files
     */
    private function sendFiles(
        ?ChannelConnection $connection,
        string $token,
        string $chatId,
        Message $message,
        Collection $files,
    ): DeliveryResult {
        $text = trim(ChannelText::forDriver($message, $this));

        /*
         * The desk writes the file's name into the body when a reply carries
         * nothing but a file. Sending it as well would caption the picture with
         * its own file name, which is noise the sender never typed.
         */
        if ($files->contains(fn (Attachment $file): bool => trim((string) $file->name) === $text)) {
            $text = '';
        }

        $caption = mb_strlen($text) <= 1024 ? $text : '';

        if ($text !== '' && $caption === '') {
            $spoken = $this->sendText($connection, $token, $chatId, $text);

            if (! $spoken->ok()) {
                return $spoken;
            }
        }

        $last = null;

        foreach ($files as $file) {
            $result = $this->sendFile($connection, $token, $chatId, $file, $caption);

            if (! $result->ok()) {
                return $result;
            }

            $caption = '';
            $last = $result;
        }

        return $last ?? DeliveryResult::failed('Nothing to send.', retryable: false);
    }

    /**
     * One file, uploaded rather than linked.
     *
     * Telegram can fetch a URL, and that is exactly what this must not do: the
     * files live on a disk that may be private, and handing out a public
     * address for an attachment would make every customer's documents readable
     * by anyone who learns the pattern.
     */
    private function sendFile(
        ?ChannelConnection $connection,
        string $token,
        string $chatId,
        Attachment $file,
        string $caption,
    ): DeliveryResult {
        $disk = Storage::disk((string) $file->disk);

        if (! $disk->exists((string) $file->path)) {
            return DeliveryResult::failed('The attachment is no longer on disk.', retryable: false);
        }

        $asPhoto = $file->isImage();
        $payload = ['chat_id' => $chatId];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)
                ->timeout((int) config('chatbot.channels.telegram.upload_timeout', 60))
                ->attach(
                    $asPhoto ? 'photo' : 'document',
                    (string) $disk->get((string) $file->path),
                    (string) $file->name,
                )
                ->post($this->endpoint($token, $asPhoto ? 'sendPhoto' : 'sendDocument'), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $messageId = $body['result']['message_id'] ?? null;

            return DeliveryResult::sent($messageId === null ? null : (string) $messageId);
        }

        return DeliveryResult::failed(
            $this->reason($body, $response->status()),
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }

    private function sendText(?ChannelConnection $connection, string $token, string $chatId, string $text): DeliveryResult
    {
        try {
            $response = ChannelHttp::for(self::KEY, $connection)
                ->asJson()
                ->post($this->endpoint($token, 'sendMessage'), ['chat_id' => $chatId, 'text' => $text]);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            return DeliveryResult::sent((string) ($body['result']['message_id'] ?? ''));
        }

        return DeliveryResult::failed(
            $this->reason($body, $response->status()),
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }

    /**
     * Telegram draws buttons, so options never become a numbered list here.
     */
    public function supportsButtons(): bool
    {
        return true;
    }

    /**
     * The message has to reach Telegram's servers, and whether it did is a fact
     * about a call this application makes — so it is queued, retried and
     * stamped like any other outward send.
     */
    public function sendsOutward(): bool
    {
        return true;
    }

    /**
     * One button per row.
     *
     * Options are answers to a question, and a row of them side by side is a
     * row of truncated labels on a phone — which is the device almost every
     * Telegram conversation happens on.
     *
     * @return array<string, mixed>|null
     */
    private function keyboard(Message $message): ?array
    {
        /*
         * «Отправить мой номер» — не вариант ответа, а просьба к Telegram.
         *
         * Inline-кнопка такого не умеет: контакт отдаёт только обычная
         * клавиатура под полем ввода, и Telegram сам подставляет в неё
         * карточку того аккаунта, с которого пишут. Набранный руками номер
         * доказательством не является — по нему кто угодно получил бы чужие
         * брони, — а этот приходит от Telegram вместе с `user_id` владельца.
         *
         * Клавиатура одноразовая: она занимает нижнюю треть экрана и после
         * ответа там не нужна.
         */
        if ((bool) (((array) $message->meta)['request_contact'] ?? false)) {
            return [
                'keyboard' => [[['text' => __('chatbot::bot.share_contact'), 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ];
        }

        $options = ChannelText::options($message);

        if ($options === []) {
            return null;
        }

        $rows = [];

        foreach ($options as $position => $label) {
            $rows[] = [[
                'text' => $label,
                /*
                 * The position, not the label. Callback data is capped at 64
                 * bytes by Telegram and a label written by a scenario author is
                 * routinely longer, so sending the label means the button that
                 * asks the longest question is the one that silently fails to
                 * be created. The engine resolves a bare number anyway.
                 */
                'callback_data' => (string) ($position + 1),
            ]];
        }

        return ['inline_keyboard' => $rows];
    }

    private function chatId(mixed $message): ?string
    {
        if (! is_array($message)) {
            return null;
        }

        $chatId = trim((string) ($message['chat']['id'] ?? ''));

        return $chatId === '' ? null : $chatId;
    }

    /**
     * The sender's own account details, as claims.
     *
     * @return array{displayName: ?string, username: ?string}
     */
    private function from(mixed $sender): array
    {
        if (! is_array($sender)) {
            return ['displayName' => null, 'username' => null];
        }

        $name = trim(implode(' ', array_filter([
            trim((string) ($sender['first_name'] ?? '')),
            trim((string) ($sender['last_name'] ?? '')),
        ])));

        $username = trim((string) ($sender['username'] ?? ''));

        return [
            'displayName' => $name === '' ? null : $name,
            'username' => $username === '' ? null : $username,
        ];
    }

    /**
     * Telegram's own words where it gave any, and the status code where it did
     * not: a bare "the request failed" sends somebody to the network tab of a
     * server they cannot see.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function reason(array $body, int $status): string
    {
        $description = trim((string) ($body['description'] ?? ''));

        return $description === ''
            ? 'Telegram refused the request with status '.$status.'.'
            : $description;
    }

    /**
     * Stops the spinner on the pressed button and takes the keyboard away.
     *
     * **A keyboard in Telegram never expires on its own.** It stays under the
     * message for ever, and the answer it sends is the option's position in a
     * question the conversation left behind hours ago. What the visitor sees is
     * the bot answering something they did not ask: they press "call a person"
     * on yesterday's menu and the bot replies that it cannot find that in the
     * knowledge base, because by then position two means something else
     * entirely. Removing the buttons once they have been used is the only thing
     * that makes a tapped answer mean what it said.
     *
     * Both calls are best-effort and neither is reported. The press has already
     * been decoded, the conversation is about to take its turn, and failing it
     * over a cosmetic call would lose the customer's answer.
     *
     * @param  array<array-key, mixed>  $callback
     */
    private function closeKeyboard(ChannelConnection $connection, array $callback, string $chatId, ?string $pressed = null): void
    {
        $token = $connection->credential('token');

        if ($token === '') {
            return;
        }

        $callbackId = trim((string) ($callback['id'] ?? ''));
        $messageId = trim((string) ($callback['message']['message_id'] ?? ''));
        $asked = trim((string) ($callback['message']['text'] ?? ''));

        try {
            if ($callbackId !== '') {
                ChannelHttp::for(self::KEY, $connection)
                    ->timeout(5)
                    ->asJson()
                    ->post($this->endpoint($token, 'answerCallbackQuery'), ['callback_query_id' => $callbackId]);
            }

            if ($messageId === '') {
                return;
            }

            /*
             * The answer is written into the question, not just taken away
             * with the buttons.
             *
             * A pressed inline button leaves nothing behind in the chat: the
             * keyboard disappears and the person is looking at a question with
             * no answer under it, two messages above the next one. Asked what
             * they chose, they cannot tell — and on a form of a dozen
             * questions that is most of the conversation gone. Telegram will
             * not let a bot post a message as the visitor, so the only place
             * the choice can be shown is the question itself.
             */
            $body = $pressed === null || $asked === ''
                ? null
                : $asked."\n\n— ".$pressed;

            if ($body === null) {
                ChannelHttp::for(self::KEY, $connection)
                    ->timeout(5)
                    ->asJson()
                    ->post($this->endpoint($token, 'editMessageReplyMarkup'), [
                        'chat_id' => $chatId,
                        'message_id' => (int) $messageId,
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);

                return;
            }

            ChannelHttp::for(self::KEY, $connection)
                ->timeout(5)
                ->asJson()
                ->post($this->endpoint($token, 'editMessageText'), [
                    'chat_id' => $chatId,
                    'message_id' => (int) $messageId,
                    'text' => mb_substr($body, 0, self::TEXT_LIMIT),
                    'reply_markup' => ['inline_keyboard' => []],
                ]);
        } catch (ConnectionException) {
            // Cosmetic; the answer itself is already in hand.
        }
    }

    /**
     * The text of the button whose data came back, or null.
     *
     * Read from the keyboard Telegram returns with the callback rather than
     * from anything stored: the message that carried the buttons may have been
     * sent by a different process, on a different day, from a queue — and the
     * one thing certain to be in hand is the update itself.
     *
     * @param  array<array-key, mixed>  $callback
     */
    private function pressedLabel(array $callback): ?string
    {
        $data = trim((string) ($callback['data'] ?? ''));
        $rows = $callback['message']['reply_markup']['inline_keyboard'] ?? null;

        if ($data === '' || ! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            foreach ((array) $row as $button) {
                if (! is_array($button)) {
                    continue;
                }

                if (trim((string) ($button['callback_data'] ?? '')) === $data) {
                    $label = trim((string) ($button['text'] ?? ''));

                    return $label === '' ? null : $label;
                }
            }
        }

        return null;
    }

    /**
     * Asks Telegram what has arrived since the cursor.
     *
     * **The updates are decoded by the same method the webhook uses.** An
     * update fetched by `getUpdates` and an update delivered to a webhook are
     * byte-for-byte the same object, so a second decoder would be a copy that
     * drifts: the first fix to button handling would land in one of them, and
     * the installation on the other path would keep the bug with nothing on
     * screen to say why. Wrapping it back into a `Request` is the cheap half of
     * that bargain.
     *
     * **A webhook in the way is removed rather than reported.** Telegram
     * refuses `getUpdates` with 409 while one is registered, and an installation
     * that has just switched to polling has one by definition — it is how it was
     * set up before the network made it impossible. Answering with the error
     * would describe a state the person has already told us to leave.
     * `drop_pending_updates` stays off, so the questions waiting in the queue
     * are the first thing polling delivers.
     */
    public function pull(ChannelConnection $connection, ?string $cursor, int $waitSeconds): PollBatch
    {
        $token = $connection->credential('token');

        if ($token === '') {
            return PollBatch::failed('No bot token.');
        }

        $response = $this->getUpdates($connection, $token, $cursor, $waitSeconds);

        if ($response instanceof PollBatch) {
            return $response;
        }

        $body = (array) $response->json();

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            if ($response->status() === 409) {
                $this->dropWebhook($connection, $token);

                $retry = $this->getUpdates($connection, $token, $cursor, $waitSeconds);

                if ($retry instanceof PollBatch) {
                    return $retry;
                }

                $response = $retry;
                $body = (array) $response->json();
            }

            if (! $response->successful() || ($body['ok'] ?? false) !== true) {
                return PollBatch::failed($this->reason($body, $response->status()));
            }
        }

        $updates = array_values(array_filter((array) ($body['result'] ?? []), 'is_array'));
        $messages = [];
        $next = $cursor;

        foreach ($updates as $update) {
            $id = (int) ($update['update_id'] ?? 0);

            if ($id > 0) {
                /*
                 * One past the highest, which is how Telegram's offset is
                 * defined: sending the id itself would fetch the same update
                 * forever, and the conversation would be answered on a loop.
                 */
                $next = (string) ($id + 1);
            }

            $inbound = $this->decode($this->asRequest($update), $connection);

            if ($inbound !== null) {
                $messages[] = $inbound;
            }
        }

        return PollBatch::of($messages, $next);
    }

    private function getUpdates(ChannelConnection $connection, string $token, ?string $cursor, int $waitSeconds): ClientResponse|PollBatch
    {
        $payload = [
            'timeout' => $waitSeconds,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        ];

        if ($cursor !== null && $cursor !== '') {
            $payload['offset'] = (int) $cursor;
        }

        try {
            /*
             * The read timeout has to outlast the long poll itself, or every
             * pull is cancelled by our own client a moment before Telegram
             * would have answered — which looks exactly like a network fault
             * and is the reason the margin is here rather than in config.
             */
            return ChannelHttp::for(self::KEY, $connection)
                ->timeout($waitSeconds + 10)
                ->asJson()
                ->post($this->endpoint($token, 'getUpdates'), $payload);
        } catch (ConnectionException $exception) {
            return PollBatch::failed($exception->getMessage());
        }
    }

    /**
     * Unregisters the webhook so that polling is allowed at all.
     *
     * Failure is deliberately silent: the caller is already about to retry, and
     * the retry's own answer is the honest report. A message here would name
     * `deleteWebhook` to somebody who asked for their messages.
     */
    private function dropWebhook(ChannelConnection $connection, string $token): void
    {
        try {
            ChannelHttp::for(self::KEY, $connection)
                ->asJson()
                ->post($this->endpoint($token, 'deleteWebhook'), ['drop_pending_updates' => false]);
        } catch (ConnectionException) {
            // The retry reports what is still wrong.
        }
    }

    /**
     * One fetched update, in the shape `decode()` reads a delivered one in.
     *
     * @param  array<array-key, mixed>  $update
     */
    private function asRequest(array $update): Request
    {
        return Request::create(
            uri: '/',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($update),
        );
    }

    private function endpoint(string $token, string $method): string
    {
        $base = rtrim((string) config('chatbot.channels.telegram.api_url', 'https://api.telegram.org'), '/');

        return $base.'/bot'.$token.'/'.$method;
    }

    /**
     * Markup stays off unless this account asks for it.
     *
     * Telegram rejects the whole message when its markup does not parse, and a
     * scenario's text carries whatever the conversation collected — a name with
     * an underscore in it, a reference number in brackets — so a parse mode
     * turns somebody else's data into a delivery failure.
     */
    private function parseMode(?ChannelConnection $connection): string
    {
        $settings = $connection?->settings ?? [];
        $fromConnection = trim((string) ($settings['parse_mode'] ?? ''));

        return $fromConnection !== ''
            ? $fromConnection
            : trim((string) config('chatbot.channels.telegram.parse_mode', ''));
    }

    private function configToken(): string
    {
        return trim((string) config('chatbot.channels.telegram.token', ''));
    }
}
