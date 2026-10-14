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
 * WhatsApp, over Meta's Cloud API.
 *
 * **Buttons are a privilege, not a given.** WhatsApp draws at most three reply
 * buttons and cuts a title at twenty characters, which is shorter than most
 * answers a scenario offers. So the keyboard is used when the options fit and
 * the same options become a numbered list when they do not — the engine accepts
 * a bare digit either way, and a question with four answers stays answerable
 * instead of arriving with the fourth missing.
 *
 * **The twenty-four hour window is Meta's rule, not this package's.** Outside
 * it, free-form messages are refused and only approved templates go through.
 * This driver sends no templates: a bot that answers what a customer asked is
 * inside the window by definition, and a scenario that waits a day for an
 * answer gets a readable refusal rather than a message the business is billed
 * for and the customer never sees.
 */
final class WhatsappDriver implements ChannelDriver, ChannelSetup
{
    public const KEY = 'whatsapp';

    /** Meta's limits on an interactive message, and the reason text wins past them. */
    private const MAX_BUTTONS = 3;

    private const BUTTON_TITLE_LIMIT = 20;

    private const BODY_LIMIT = 1024;

    public function __construct(
        private readonly ChannelConnections $connections,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'WhatsApp';
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
                'label' => 'Access token',
                'secret' => true,
                'required' => true,
                'hint' => 'A permanent System User token. The one shown in the dashboard expires in a day and takes the channel with it.',
            ],
            'phone_number_id' => [
                'label' => 'Phone number id',
                'required' => true,
                'hint' => 'Digits from the WhatsApp account, not the phone number itself.',
            ],
            'verify_token' => [
                'label' => 'Verify token',
                'secret' => true,
                'required' => true,
                'hint' => 'A string you choose. Meta sends it back once, while the address is being verified, and never again.',
            ],
            'app_secret' => [
                'label' => 'App secret',
                'secret' => true,
                'required' => true,
                'hint' => 'From the app settings. Every delivery is signed with it, and without it there is nothing to tell Meta apart from a stranger.',
            ],
            'proxy' => [
                'label' => 'Proxy',
                'secret' => true,
                'hint' => 'Optional, e.g. socks5://127.0.0.1:1080. Applies only to calls this application makes; the delivery arrives the other way.',
            ],
        ];
    }

    public function verify(ChannelConnection $connection): ChannelStatus
    {
        $token = $connection->credential('token');
        $phoneNumberId = $connection->credential('phone_number_id');

        if ($token === '' || $phoneNumberId === '') {
            return ChannelStatus::failed('An access token and a phone number id are both needed.');
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)
                ->withToken($token)
                ->get($this->endpoint($phoneNumberId), ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if (! $response->successful()) {
            return ChannelStatus::failed($this->reason($body, $response->status()));
        }

        $name = trim((string) ($body['verified_name'] ?? ''));
        $number = trim((string) ($body['display_phone_number'] ?? ''));

        return ChannelStatus::ok(trim($name.' '.$number) ?: 'Meta accepted the token.');
    }

    /**
     * Meta registers the address in its own dashboard, and so does a person.
     *
     * The Graph API can subscribe an app to a webhook field, but the callback
     * address and its verify token belong to the app's configuration page —
     * where the verification request is also triggered. A button here could
     * only report success for half of that.
     */
    public function installWebhook(ChannelConnection $connection, string $url): ChannelStatus
    {
        return ChannelStatus::manual(
            'Paste this address into the app: WhatsApp → Configuration → Callback URL, with the verify token saved here, '
            .'then subscribe the app to the messages field. Meta verifies the address by calling it once.',
        );
    }

    /**
     * The signature over the exact bytes Meta sent.
     *
     * The raw body, not the decoded array: re-encoding JSON changes spacing and
     * key order, and the signature is over bytes. This is the usual way a
     * signature check ends up passing in tests and failing in production.
     */
    public function authentic(Request $request, ChannelConnection $connection): bool
    {
        $secret = $connection->credential('app_secret');

        if ($secret === '') {
            return false;
        }

        $header = trim((string) $request->headers->get('X-Hub-Signature-256', ''));

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, substr($header, 7));
    }

    /**
     * One customer message out of Meta's envelope, or nothing.
     *
     * The envelope can carry several entries and several changes, and most of
     * what arrives is not a message at all: delivery receipts and read marks
     * come through the same field. One message per request is taken on purpose
     * — the router opens threads and lets the bot answer, and a batch would
     * have the bot answering twice to one webhook.
     */
    public function decode(Request $request, ChannelConnection $connection): ?InboundMessage
    {
        $payload = (array) $request->json()->all();

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $message = (array) (($value['messages'][0] ?? []));

                if ($message === []) {
                    continue;
                }

                $from = trim((string) ($message['from'] ?? ''));
                $text = $this->text($message);

                if ($from === '' || $text === '') {
                    continue;
                }

                $contact = (array) ($value['contacts'][0] ?? []);
                $name = trim((string) ($contact['profile']['name'] ?? ''));

                return new InboundMessage(
                    channel: self::KEY,
                    externalConversationId: $from,
                    externalMessageId: trim((string) ($message['id'] ?? '')) ?: null,
                    body: $text,
                    displayName: $name === '' ? null : $name,
                    /*
                     * The sender's number is the thread's address, and WhatsApp
                     * proved it by delivering from it — so it is a contact this
                     * package may believe, unlike a number typed into a widget.
                     */
                    phone: '+'.ltrim($from, '+'),
                    botId: $connection->bot_id,
                );
            }
        }

        return null;
    }

    /**
     * The verification handshake, and an empty 200 for everything else.
     *
     * The challenge is answered only when the token matches. Echoing it
     * regardless would let anybody who guessed the address point their own app
     * at it.
     */
    public function acknowledge(Request $request, ChannelConnection $connection): Response
    {
        if (! $request->isMethod('GET')) {
            return response()->noContent();
        }

        $expected = $connection->credential('verify_token');
        $given = trim((string) $request->query('hub_verify_token', ''));

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid verify token.'], 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        $to = trim((string) $conversation->external_id);

        if ($to === '') {
            return DeliveryResult::failed('The conversation has no WhatsApp number.', retryable: false);
        }

        $connection = $this->connections->forConversation($conversation);
        $token = $connection?->credential('token') ?? '';
        $phoneNumberId = $connection?->credential('phone_number_id') ?? '';

        if ($token === '' || $phoneNumberId === '') {
            return DeliveryResult::failed('This bot has no WhatsApp number connected.', retryable: false);
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)
                ->withToken($token)
                ->asJson()
                ->post($this->endpoint($phoneNumberId).'/messages', $this->payload($message, $to));
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful()) {
            $messageId = $body['messages'][0]['id'] ?? null;

            return DeliveryResult::sent($messageId === null ? null : (string) $messageId);
        }

        /*
         * 4xx from Meta is a verdict about the request — an expired token, a
         * number outside the 24-hour window, a template that does not exist —
         * and it does not change by being repeated. 429 and 5xx do.
         */
        $retryable = $response->status() === 429 || $response->status() >= 500;

        return DeliveryResult::failed($this->reason($body, $response->status()), retryable: $retryable);
    }

    /**
     * True, because the driver does draw buttons where Meta allows them.
     *
     * The per-message decision cannot live here — this method knows nothing
     * about the message — so {@see payload()} makes it, and falls back to a
     * numbered list in the same text it would otherwise have sent.
     */
    public function supportsButtons(): bool
    {
        return true;
    }

    public function sendsOutward(): bool
    {
        return true;
    }

    /**
     * The request body: interactive where the options fit, plain text otherwise.
     *
     * @return array<string, mixed>
     */
    private function payload(Message $message, string $to): array
    {
        $options = ChannelText::options($message);
        $body = trim((string) $message->body);

        if ($this->fitsButtons($options)) {
            $buttons = [];

            foreach ($options as $position => $label) {
                $buttons[] = [
                    'type' => 'reply',
                    'reply' => [
                        /*
                         * The position, which is what comes back in the reply's
                         * id and what the engine resolves. The label is for the
                         * person and may repeat; the id may not.
                         */
                        'id' => (string) ($position + 1),
                        'title' => $label,
                    ],
                ];
            }

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => mb_substr($body === '' ? '…' : $body, 0, self::BODY_LIMIT)],
                    'action' => ['buttons' => $buttons],
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => ChannelText::numbered($body, $options)],
        ];
    }

    /**
     * @param  list<string>  $options
     */
    private function fitsButtons(array $options): bool
    {
        if ($options === [] || count($options) > self::MAX_BUTTONS) {
            return false;
        }

        foreach ($options as $label) {
            if (mb_strlen($label) > self::BUTTON_TITLE_LIMIT) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the customer said: typed text, or the button they tapped.
     *
     * A tapped button answers with its id, which is the option's position —
     * the same thing a person typing "2" sends, and the same thing the engine
     * resolves.
     *
     * @param  array<array-key, mixed>  $message
     */
    private function text(array $message): string
    {
        $type = trim((string) ($message['type'] ?? ''));

        return match ($type) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['payload'] ?? $message['button']['text'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['id']
                ?? $message['interactive']['list_reply']['id']
                ?? ''
            )),
            default => '',
        };
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) config('chatbot.channels.whatsapp.api_url', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function reason(array $body, int $status): string
    {
        $message = trim((string) ($body['error']['message'] ?? ''));

        return $message === ''
            ? 'WhatsApp refused the request with status '.$status.'.'
            : $message;
    }
}
