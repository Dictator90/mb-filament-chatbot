<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use MB\FilamentChatbot\Channels\ChannelConnections;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\ChannelHttp;
use MB\FilamentChatbot\Channels\ChannelSetup;
use MB\FilamentChatbot\Channels\ChannelStatus;
use MB\FilamentChatbot\Channels\ChannelText;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Symfony\Component\HttpFoundation\Response;

/**
 * MAX, over its Bot API.
 *
 * Close enough to Telegram to be mistaken for it and different in the three
 * places that matter: the token goes in an `Authorization` header rather than
 * the path, the keyboard is an attachment rather than `reply_markup`, and the
 * recipient goes in the query string rather than the body. Writing it as a
 * subclass of the Telegram driver was tried and abandoned — the shared part is
 * four lines, and the inherited parts were exactly the ones that had to be
 * overridden.
 *
 * **A dialog accepts two messages a second.** A scenario that says three things
 * in a row is ordinary, so the limit is real rather than theoretical; delivery
 * is queued and retried, and a 429 is treated as retryable so the worker spaces
 * them out instead of dropping the third sentence.
 */
final class MaxDriver implements ChannelDriver, ChannelSetup
{
    public const KEY = 'max';

    /**
     * MAX truncates nothing: a message over the limit is refused whole, so the
     * text is cut here where the cut can at least be deliberate.
     */
    private const TEXT_LIMIT = 4000;

    public function __construct(
        private readonly ChannelConnections $connections,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'MAX';
    }

    public function isConfigured(): bool
    {
        return ChannelConnection::query()->where('channel', self::KEY)->active()->exists();
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
                'hint' => 'From the MAX developer cabinet. Sent in the Authorization header; the API no longer accepts it in the query string.',
            ],
            'secret' => [
                'label' => 'Webhook secret',
                'secret' => true,
                'required' => true,
                'hint' => 'Five to 256 letters, digits or hyphens. MAX returns it in the X-Max-Bot-Api-Secret header with every update.',
            ],
            'proxy' => [
                'label' => 'Proxy',
                'secret' => true,
                'hint' => 'Optional, e.g. socks5://127.0.0.1:1080. Applies only to calls this application makes; the webhook arrives the other way.',
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
            $response = $this->client($token, $connection)->get($this->endpoint('me'));
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        if (! $response->successful()) {
            return ChannelStatus::failed($this->reason((array) $response->json(), $response->status()));
        }

        $body = (array) $response->json();
        $username = trim((string) ($body['username'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));

        return ChannelStatus::ok(match (true) {
            $username !== '' => '@'.$username,
            $name !== '' => $name,
            default => 'MAX accepted the token.',
        });
    }

    /**
     * Subscribes this address to the updates the package can act on.
     *
     * The subscription is narrowed on purpose. MAX will deliver everything it
     * knows — members joining, chats being titled, messages being removed — and
     * every one of those would arrive, be decoded to nothing and still cost a
     * request through the throttle that protects the real ones.
     *
     * **MAX requires HTTPS on port 443.** Said here rather than discovered from
     * a refusal, because the refusal names the URL and not the reason.
     */
    public function installWebhook(ChannelConnection $connection, string $url): ChannelStatus
    {
        $token = $connection->credential('token');
        $secret = $connection->credential('secret');

        if ($token === '') {
            return ChannelStatus::failed('No bot token.');
        }

        if ($secret === '') {
            return ChannelStatus::failed('No webhook secret: MAX would be indistinguishable from anybody else who learns the address.');
        }

        if (! str_starts_with($url, 'https://')) {
            return ChannelStatus::failed('MAX delivers to HTTPS addresses only, on port 443.');
        }

        try {
            $response = $this->client($token, $connection)->post($this->endpoint('subscriptions'), [
                'url' => $url,
                'secret' => $secret,
                'update_types' => ['message_created', 'message_callback', 'bot_started'],
            ]);
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        return $response->successful()
            ? ChannelStatus::ok('MAX will deliver updates to this address.')
            : ChannelStatus::failed($this->reason((array) $response->json(), $response->status()));
    }

    /**
     * The shared secret, in MAX's own header.
     *
     * An unconfigured secret refuses everything, for the same reason as
     * everywhere else in this package: a forgotten setting must not become an
     * open door into the customer desk that works perfectly in testing.
     */
    public function authentic(Request $request, ChannelConnection $connection): bool
    {
        $expected = $connection->credential('secret');

        if ($expected === '') {
            return false;
        }

        $given = trim((string) $request->headers->get('X-Max-Bot-Api-Secret', ''));

        return $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Understands a text message and a button press; everything else is nothing.
     *
     * The thread is keyed by the chat id rather than by the sender: MAX delivers
     * a dialog as a chat of two, and the sender id would open a second thread
     * the moment the same person wrote from a group.
     */
    public function decode(Request $request, ChannelConnection $connection): ?InboundMessage
    {
        $update = (array) $request->json()->all();
        $type = trim((string) ($update['update_type'] ?? ''));

        if ($type === 'message_callback') {
            $callback = (array) ($update['callback'] ?? []);
            $chatId = $this->chatId($update['message'] ?? null);
            $payload = trim((string) ($callback['payload'] ?? ''));

            if ($chatId === null || $payload === '') {
                return null;
            }

            $sender = $this->from($callback['user'] ?? null);

            return new InboundMessage(
                channel: self::KEY,
                externalConversationId: $chatId,
                /*
                 * Prefixed like Telegram's, and for the same reason: a callback
                 * id and a message id come from different sequences, and an
                 * unprefixed collision would be read as a redelivery and
                 * silently dropped.
                 */
                externalMessageId: 'callback:'.(string) ($callback['callback_id'] ?? ''),
                body: $payload,
                displayName: $sender['displayName'],
                username: $sender['username'],
                botId: $connection->bot_id,
            );
        }

        if ($type !== 'message_created') {
            return null;
        }

        $message = (array) ($update['message'] ?? []);
        $chatId = $this->chatId($message);
        $text = trim((string) (($message['body']['text'] ?? '')));

        if ($chatId === null || $text === '') {
            return null;
        }

        $sender = $this->from($message['sender'] ?? null);

        return new InboundMessage(
            channel: self::KEY,
            externalConversationId: $chatId,
            externalMessageId: trim((string) ($message['body']['mid'] ?? '')) ?: null,
            body: $text,
            displayName: $sender['displayName'],
            username: $sender['username'],
            botId: $connection->bot_id,
        );
    }

    /**
     * 200 for anything that got past the secret.
     *
     * MAX redelivers what it reads as a failure, so an update this package has
     * no words for must still be accepted — otherwise a single sticker becomes
     * a retry loop.
     *
     * **A button press leaves a spinner on the sender's screen** until the bot
     * answers the callback through `POST /answers`, which this driver does not
     * do: the reply itself arrives a moment later through the queue and clears
     * it. Known and deliberate — answering here would mean a second provider
     * call inside the request the webhook is holding open.
     */
    public function acknowledge(Request $request, ChannelConnection $connection): Response
    {
        return response()->noContent();
    }

    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        $chatId = trim((string) $conversation->external_id);

        if ($chatId === '') {
            return DeliveryResult::failed('The conversation has no MAX chat id.', retryable: false);
        }

        $connection = $this->connections->forConversation($conversation);
        $token = $connection?->credential('token') ?? '';

        if ($token === '') {
            return DeliveryResult::failed('This bot has no MAX account connected.', retryable: false);
        }

        $payload = [
            'text' => mb_substr(ChannelText::forDriver($message, $this), 0, self::TEXT_LIMIT),
        ];

        $keyboard = $this->keyboard($message);

        if ($keyboard !== null) {
            $payload['attachments'] = [$keyboard];
        }

        try {
            $response = $this->client($token, $connection)->post($this->endpoint('messages').'?chat_id='.urlencode($chatId), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        if ($response->successful()) {
            $body = (array) $response->json();
            $messageId = $body['message']['body']['mid'] ?? null;

            return DeliveryResult::sent($messageId === null ? null : (string) $messageId);
        }

        /*
         * 429 is the two-a-second limit, which passes; 5xx is MAX having a bad
         * minute. Everything else is a verdict about the request itself — a
         * blocked bot, a chat that is gone — and repeating it only spends the
         * worker's time to be told the same thing.
         */
        $retryable = $response->status() === 429 || $response->status() >= 500;

        return DeliveryResult::failed($this->reason((array) $response->json(), $response->status()), retryable: $retryable);
    }

    public function supportsButtons(): bool
    {
        return true;
    }

    public function sendsOutward(): bool
    {
        return true;
    }

    /**
     * One button per row, carrying the option's position.
     *
     * The position rather than the label, as everywhere else: the payload is a
     * short field and a scenario's labels are written for people, so sending
     * the label is how the longest option becomes the one that silently fails.
     *
     * @return array{type: string, payload: array{buttons: list<list<array{type: string, text: string, payload: string}>>}}|null
     */
    private function keyboard(Message $message): ?array
    {
        $options = ChannelText::options($message);

        if ($options === []) {
            return null;
        }

        $rows = [];

        foreach ($options as $position => $label) {
            $rows[] = [[
                'type' => 'callback',
                'text' => $label,
                'payload' => (string) ($position + 1),
            ]];
        }

        return [
            'type' => 'inline_keyboard',
            'payload' => ['buttons' => $rows],
        ];
    }

    private function chatId(mixed $message): ?string
    {
        if (! is_array($message)) {
            return null;
        }

        $chatId = trim((string) ($message['recipient']['chat_id'] ?? ''));

        return $chatId === '' ? null : $chatId;
    }

    /**
     * @return array{displayName: ?string, username: ?string}
     */
    private function from(mixed $sender): array
    {
        if (! is_array($sender)) {
            return ['displayName' => null, 'username' => null];
        }

        $name = trim((string) ($sender['name'] ?? ''));
        $username = trim((string) ($sender['username'] ?? ''));

        return [
            'displayName' => $name === '' ? null : $name,
            'username' => $username === '' ? null : $username,
        ];
    }

    private function client(string $token, ?ChannelConnection $connection = null): PendingRequest
    {
        return ChannelHttp::for(self::KEY, $connection)
            ->asJson()
            ->withHeaders(['Authorization' => $token]);
    }

    private function endpoint(string $method): string
    {
        $base = rtrim((string) config('chatbot.channels.max.api_url', 'https://platform-api2.max.ru'), '/');

        return $base.'/'.$method;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function reason(array $body, int $status): string
    {
        $message = trim((string) ($body['message'] ?? $body['error'] ?? ''));

        return $message === ''
            ? 'MAX refused the request with status '.$status.'.'
            : $message;
    }
}
