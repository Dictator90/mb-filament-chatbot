<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\ChannelText;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

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
 */
final class TelegramDriver implements ChannelDriver
{
    public const KEY = 'telegram';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Telegram';
    }

    /**
     * No token, no channel.
     *
     * Reported rather than thrown so that an installation which has not been
     * given a bot yet still boots, still shows Telegram on the settings screen,
     * and fails one message with a readable reason instead of every request with
     * a stack trace.
     */
    public function isConfigured(): bool
    {
        return $this->token() !== '';
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

        $payload = [
            'chat_id' => $chatId,
            'text' => ChannelText::forDriver($message, $this),
        ];

        $parseMode = trim((string) config('chatbot.channels.telegram.parse_mode', ''));

        if ($parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        $keyboard = $this->keyboard($message);

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard;
        }

        try {
            $response = Http::timeout((int) config('chatbot.channels.telegram.timeout', 10))
                ->asJson()
                ->post($this->endpoint('sendMessage'), $payload);
        } catch (ConnectionException $exception) {
            return DeliveryResult::failed($exception->getMessage());
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $messageId = $body['result']['message_id'] ?? null;

            return DeliveryResult::sent($messageId === null ? null : (string) $messageId);
        }

        $description = trim((string) ($body['description'] ?? ''));

        if ($description === '') {
            $description = 'Telegram refused the message with status '.$response->status().'.';
        }

        /*
         * 4xx other than 429 is Telegram saying the request itself is wrong — a
         * blocked bot, a chat that no longer exists, text over the limit. None of
         * those change by being sent again, and retrying them burns the budget
         * on a verdict that is already final.
         */
        $retryable = $response->status() === 429 || $response->status() >= 500;

        return DeliveryResult::failed($description, retryable: $retryable);
    }

    /**
     * Telegram draws buttons, so options never become a numbered list here.
     */
    public function supportsButtons(): bool
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

    private function endpoint(string $method): string
    {
        $base = rtrim((string) config('chatbot.channels.telegram.api_url', 'https://api.telegram.org'), '/');

        return $base.'/bot'.$this->token().'/'.$method;
    }

    private function token(): string
    {
        return trim((string) config('chatbot.channels.telegram.token', ''));
    }
}
