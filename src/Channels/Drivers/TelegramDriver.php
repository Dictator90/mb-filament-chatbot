<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels\Drivers;

use Illuminate\Http\Client\ConnectionException;
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
final class TelegramDriver implements ChannelDriver, ChannelSetup
{
    public const KEY = 'telegram';

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
                body: trim((string) ($callback['data'] ?? '')),
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
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if ($chatId === null || $text === '') {
            return null;
        }

        $sender = $this->from($message['from'] ?? null);

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
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null
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
