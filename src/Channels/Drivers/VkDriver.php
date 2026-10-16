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
 * VK community messages, over the Callback API.
 *
 * The odd one of the four in three ways, and all three are load-bearing.
 *
 * **The reply body matters.** VK wants the literal string `ok` and treats
 * anything else — an empty 200 included — as a delivery failure worth
 * retrying, so the same message arrives again and again until the community is
 * paused. Nothing else in this package answers a webhook with a body.
 *
 * **Setup happens through the same address.** Before any events flow, VK posts
 * a `confirmation` event and expects a string back that a person copies out of
 * the community's settings. So the address has to be live, and answering with a
 * secret-checked string is the whole handshake.
 *
 * **The secret travels in the body**, not in a header, because the Callback API
 * predates that convention. It is still the only thing distinguishing VK from
 * anybody who learns the address.
 */
final class VkDriver implements ChannelDriver, ChannelSetup
{
    public const KEY = 'vk';

    /**
     * Pinned rather than left to VK's default.
     *
     * The API's shapes change between versions, and an unpinned call silently
     * follows whatever VK is currently defaulting to — which is how a working
     * integration breaks on a day nobody deployed anything.
     */
    private const API_VERSION = '5.199';

    public function __construct(
        private readonly ChannelConnections $connections,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'VK';
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
                'label' => __('chatbot::panel.channel_fields.vk.token.label'),
                'secret' => true,
                'required' => true,
                'hint' => __('chatbot::panel.channel_fields.vk.token.hint'),
            ],
            'secret' => [
                'label' => __('chatbot::panel.channel_fields.vk.secret.label'),
                'secret' => true,
                'required' => true,
                'hint' => __('chatbot::panel.channel_fields.vk.secret.hint'),
            ],
            'confirmation' => [
                'label' => __('chatbot::panel.channel_fields.vk.confirmation.label'),
                'required' => true,
                'hint' => __('chatbot::panel.channel_fields.vk.confirmation.hint'),
            ],
            'group_id' => [
                'label' => __('chatbot::panel.channel_fields.vk.group_id.label'),
                'hint' => __('chatbot::panel.channel_fields.vk.group_id.hint'),
            ],
            'proxy' => [
                'label' => __('chatbot::panel.channel_fields.proxy.label'),
                'secret' => true,
                'hint' => __('chatbot::panel.channel_fields.proxy.hint'),
            ],
        ];
    }

    public function verify(ChannelConnection $connection): ChannelStatus
    {
        $token = $connection->credential('token');

        if ($token === '') {
            return ChannelStatus::failed('No community access token.');
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)->asForm()->post($this->endpoint('groups.getById'), [
                'access_token' => $token,
                'v' => self::API_VERSION,
            ]);
        } catch (ConnectionException $exception) {
            return ChannelStatus::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if (isset($body['error'])) {
            return ChannelStatus::failed($this->reason($body, $response->status()));
        }

        $group = $body['response']['groups'][0] ?? $body['response'][0] ?? null;
        $name = is_array($group) ? trim((string) ($group['name'] ?? '')) : '';

        return ChannelStatus::ok($name === '' ? 'VK accepted the token.' : $name);
    }

    /**
     * VK has no API for this, and saying so is the honest answer.
     *
     * `groups.setCallbackSettings` exists, but it configures a server VK
     * already knows about — the address itself is added and confirmed in the
     * community's own settings, by a person who can see the confirmation
     * string. Pretending otherwise would mean a button that reports success
     * while nothing is connected.
     */
    public function installWebhook(ChannelConnection $connection, string $url): ChannelStatus
    {
        return ChannelStatus::manual(
            'Paste this address into the community: Settings → API usage → Callback API, then press Confirm. '
            .'The secret key and the confirmation string must match what is saved here.',
        );
    }

    /**
     * The secret inside the event, and the community it claims to be from.
     *
     * The community check is optional because the id is not always known when
     * the connection is first saved, and refusing everything until it is filled
     * in would make the setup unfinishable.
     */
    public function authentic(Request $request, ChannelConnection $connection): bool
    {
        $expected = $connection->credential('secret');

        if ($expected === '') {
            return false;
        }

        $payload = (array) $request->json()->all();
        $given = trim((string) ($payload['secret'] ?? ''));

        if ($given === '' || ! hash_equals($expected, $given)) {
            return false;
        }

        $group = $connection->credential('group_id');

        if ($group === '') {
            return true;
        }

        return trim((string) ($payload['group_id'] ?? '')) === $group;
    }

    /**
     * Only a new message becomes an inbound message.
     *
     * Button presses arrive the same way: a keyboard built with `payload`
     * delivers the press as an ordinary message whose text is the button's
     * label, and the payload beside it carries the option's position. The
     * position wins when it is there, because two options can share a label and
     * the engine resolves a digit exactly.
     */
    public function decode(Request $request, ChannelConnection $connection): ?InboundMessage
    {
        $payload = (array) $request->json()->all();

        if (trim((string) ($payload['type'] ?? '')) !== 'message_new') {
            return null;
        }

        $message = (array) ($payload['object']['message'] ?? $payload['object'] ?? []);
        $peerId = trim((string) ($message['peer_id'] ?? $message['from_id'] ?? ''));
        $text = trim((string) ($message['text'] ?? ''));
        $chosen = $this->chosenOption($message['payload'] ?? null);

        if ($peerId === '' || ($text === '' && $chosen === null)) {
            return null;
        }

        return new InboundMessage(
            channel: self::KEY,
            externalConversationId: $peerId,
            externalMessageId: trim((string) ($message['id'] ?? '')) ?: null,
            body: $chosen ?? $text,
            botId: $connection->bot_id,
        );
    }

    /**
     * `ok` for events, the confirmation string while the address is being added.
     *
     * Plain text on purpose: VK compares the body, and a JSON-quoted `"ok"` is
     * not `ok`.
     */
    public function acknowledge(Request $request, ChannelConnection $connection): Response
    {
        $payload = (array) $request->json()->all();

        if (trim((string) ($payload['type'] ?? '')) === 'confirmation') {
            return response($connection->credential('confirmation'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    }

    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        $peerId = trim((string) $conversation->external_id);

        if ($peerId === '') {
            return DeliveryResult::failed('The conversation has no VK peer id.', retryable: false);
        }

        $connection = $this->connections->forConversation($conversation);
        $token = $connection?->credential('token') ?? '';

        if ($token === '') {
            return DeliveryResult::failed('This bot has no VK community connected.', retryable: false);
        }

        $payload = [
            'access_token' => $token,
            'v' => self::API_VERSION,
            'peer_id' => $peerId,
            'message' => ChannelText::forDriver($message, $this),
            /*
             * VK deduplicates by this number within a day, so it has to differ
             * per send and be stable per retry. The message's own id is both:
             * a retried job sends the same row again, and VK answers with the
             * original message rather than delivering a second copy.
             */
            'random_id' => (int) $message->getKey(),
        ];

        $keyboard = $this->keyboard($message);

        if ($keyboard !== null) {
            $payload['keyboard'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = ChannelHttp::for(self::KEY, $connection)->asForm()->post($this->endpoint('messages.send'), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if (! isset($body['error']) && $response->successful()) {
            $messageId = $body['response'] ?? null;

            return DeliveryResult::sent(is_scalar($messageId) ? (string) $messageId : null);
        }

        /*
         * VK answers 200 with an error object, so the status says nothing. Code
         * 6 is "too many requests a second" and 10 is an internal server error;
         * everything else — no permission, the person blocked the community, a
         * peer that does not exist — is final and worth reporting once.
         */
        $code = (int) ($body['error']['error_code'] ?? 0);

        return DeliveryResult::failed(
            $this->reason($body, $response->status()),
            retryable: in_array($code, [6, 10], strict: true) || $response->status() >= 500,
        );
    }

    /**
     * VK draws the keyboard, so the options stay out of the text.
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
     * An inline keyboard, one button per row.
     *
     * Inline rather than the one that replaces the person's keyboard: this one
     * stays attached to the question it belongs to, so an answer tapped after
     * three more messages is still unambiguous.
     *
     * The payload carries the position and the label carries the words. VK caps
     * a label at 40 characters and refuses the whole keyboard over it, so a long
     * option is cut rather than allowed to silently remove every button from the
     * question.
     *
     * @return array{inline: bool, buttons: list<list<array{action: array{type: string, label: string, payload: string}}>>}|null
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
                'action' => [
                    'type' => 'text',
                    'label' => mb_substr($label, 0, 40),
                    'payload' => json_encode(['option' => $position + 1], JSON_UNESCAPED_UNICODE),
                ],
            ]];
        }

        return ['inline' => true, 'buttons' => $rows];
    }

    /**
     * The option's number out of a button's payload, where there is one.
     *
     * VK hands the payload back as a JSON string, and a person typing a reply
     * sends none at all — so anything that is not this package's own shape is
     * treated as no choice rather than as a malformed one.
     */
    private function chosenOption(mixed $payload): ?string
    {
        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, associative: true);

        if (! is_array($decoded)) {
            return null;
        }

        $option = (int) ($decoded['option'] ?? 0);

        return $option > 0 ? (string) $option : null;
    }

    private function endpoint(string $method): string
    {
        $base = rtrim((string) config('chatbot.channels.vk.api_url', 'https://api.vk.com/method'), '/');

        return $base.'/'.$method;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function reason(array $body, int $status): string
    {
        $message = trim((string) ($body['error']['error_msg'] ?? ''));

        return $message === ''
            ? 'VK refused the request with status '.$status.'.'
            : $message;
    }
}
