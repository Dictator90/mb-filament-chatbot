<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\Request;
use MB\FilamentChatbot\Channels\Drivers\TelegramDriver;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where Telegram delivers updates.
 *
 * The URL is public and unguessable only for as long as nobody guesses it, so
 * the secret header is what actually authenticates the caller. Telegram attaches
 * it to every update when the webhook is registered with one, and comparing it
 * is the whole of the check: without it, anyone who learns the path can post a
 * message that appears in the desk as coming from a customer, with a chat id of
 * their choosing.
 */
class TelegramWebhookController
{
    public function __construct(
        private readonly MessageRouter $router,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->authentic($request)) {
            return response()->json(['message' => 'Invalid secret token.'], 403);
        }

        $inbound = $this->decode((array) $request->json()->all());

        if ($inbound !== null) {
            $this->router->receive($inbound);
        }

        /*
         * 200 for anything that got past the secret, including updates this
         * package makes nothing of. Telegram treats any other status as a
         * failure and redelivers the same update on a schedule, so answering a
         * sticker with an error would produce an endless retry of something
         * there is no version of the code that will ever accept.
         */
        return response()->noContent();
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
    private function authentic(Request $request): bool
    {
        $expected = trim((string) config('chatbot.channels.telegram.secret_token', ''));

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
     * The keyboard was built with the position in the callback data for a reason
     * (see the driver), and the engine resolves a bare digit against the same
     * ordering.
     */
    private function decode(array $update): ?InboundMessage
    {
        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $chatId = $this->chatId($callback['message'] ?? null);

            if ($chatId === null) {
                return null;
            }

            $sender = $this->from($callback['from'] ?? null);

            return new InboundMessage(
                channel: TelegramDriver::KEY,
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
            channel: TelegramDriver::KEY,
            externalConversationId: $chatId,
            externalMessageId: (string) ($message['message_id'] ?? ''),
            body: $text,
            displayName: $sender['displayName'],
            username: $sender['username'],
        );
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
}
