<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDelivery;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Widget;

beforeEach(function (): void {
    config([
        'chatbot.widget.enabled' => true,
        'chatbot.widget.origins' => ['https://shop.example.com'],
    ]);

    $this->bot = Bot::factory()->create(['slug' => 'demo']);
    $this->widget = Widget::factory()->create([
        'key' => 'demo',
        'bot_id' => $this->bot->getKey(),
    ]);

    [$this->conversation, $this->token] = receiptThread();
});

/**
 * A site conversation and the token that reaches it.
 *
 * @return array{0: Conversation, 1: string}
 */
function receiptThread(): array
{
    $token = VisitorToken::issue();

    $conversation = Conversation::factory()->create([
        'channel' => 'site',
        'visitor_key' => VisitorToken::hash($token),
    ]);

    return [$conversation, $token];
}

/**
 * One outgoing line in a site conversation, written the way the engine writes
 * one — through the model, so the observer and the outbox both take their turn.
 */
function botLine(Conversation $conversation, string $body = 'On its way.'): Message
{
    return Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => $body,
    ]);
}

/**
 * One poll of the transcript, as the widget makes it.
 */
function pollTranscript(string $token, int $after = 0): TestResponse
{
    return test()->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $token,
    ])->getJson('/chatbot/site/messages?after='.$after);
}

/**
 * How many statements that change something ran while the callback did.
 *
 * Counted rather than capped: what this is used for is the difference between
 * "writes when there is something to write" and "writes on every poll", and a
 * ceiling would pass for either as long as the number was small enough.
 */
function writesDuring(Closure $work): int
{
    $writes = 0;

    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $work();

    return $writes;
}

/**
 * On the site channel the queue knows nothing about the visitor.
 *
 * The message is written, the job runs — synchronously here, which is the most
 * favourable case the queue ever gets — and the row is still not delivered,
 * because nothing has reached anybody: the widget fetches the transcript itself.
 * Stamped by the job, the column said "delivered" wherever a worker happened to
 * be running and "sending…" wherever one was not, and in the installation this
 * came from thirty-seven jobs were waiting with nobody to run them while the
 * operator watched a clock under a message the visitor had already answered.
 */
it('counts a site message delivered when the widget fetches it, not when the queue runs it', function (): void {
    $message = botLine($this->conversation);

    expect($message->refresh()->delivered_at)->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->deliveryStage($this->conversation))->toBe(MessageDelivery::Sent);

    pollTranscript($this->token)->assertOk();

    $message->refresh();

    expect($message->delivered_at)->not->toBeNull()
        ->and($message->deliveryStage($this->conversation))->toBe(MessageDelivery::Delivered);
});

/**
 * Reading is reported once and must hold.
 *
 * The stage is derived from the conversation's watermark rather than stamped on
 * the row, so the risk this guards is the other way round from the usual one:
 * not a mark that never arrives, but one that a later poll quietly recomputes
 * away. Ticks that go backwards read to an operator as a message the customer
 * somehow un-received.
 */
it('raises a fetched message to read on the visitor mark, and never lowers it again', function (): void {
    $message = botLine($this->conversation);

    pollTranscript($this->token)->assertOk();

    $this->travelTo(now()->addMinute());

    test()->withHeaders([
        'Origin' => 'https://shop.example.com',
        'X-Chatbot-Token' => $this->token,
    ])->postJson('/chatbot/site/read')->assertOk();

    expect($message->refresh()->deliveryStage($this->conversation->refresh()))
        ->toBe(MessageDelivery::Read);

    pollTranscript($this->token, (int) $message->getKey())->assertOk();
    pollTranscript($this->token, (int) $message->getKey())->assertOk();

    expect($message->refresh()->deliveryStage($this->conversation->refresh()))
        ->toBe(MessageDelivery::Read);
});

/**
 * The transcript endpoint is a poll: every few seconds, per open widget, all
 * day. A delivery stamp written on whatever the poll returned would be a write
 * per message per poll — the same rows re-stamped forever, on the one table
 * that grows without limit.
 *
 * The property, not a ceiling: confirming costs a write when there is something
 * to confirm and nothing at all when there is not.
 */
it('writes nothing on a poll that confirms nothing new', function (): void {
    botLine($this->conversation, 'first');
    botLine($this->conversation, 'second');

    $confirming = writesDuring(fn () => pollTranscript($this->token)->assertOk());
    $again = writesDuring(fn () => pollTranscript($this->token)->assertOk());

    expect($confirming)->toBeGreaterThan(0)
        ->and($again)->toBe(0);
});

/**
 * A channel that really does hand the message to somebody else's server keeps
 * the old meaning: the provider accepting it is the delivery, and there is no
 * later fetch that could confirm it.
 */
it('still stamps delivery from the send itself on a channel that sends outward', function (): void {
    config()->set('chatbot.channels.telegram.enabled', true);
    config()->set('chatbot.channels.telegram.token', 'bot-token');

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]])]);

    $conversation = Conversation::factory()->create(['channel' => 'telegram', 'external_id' => '55']);

    $message = botLine($conversation, 'Pick one');

    expect($message->refresh()->delivered_at)->not->toBeNull()
        ->and($message->deliveryStage($conversation))->toBe(MessageDelivery::Delivered);
});

/**
 * The switch belongs to the placement, like the other two beside it.
 *
 * A shop puts the same bot on a support page, where seeing that somebody read
 * the question is reassuring, and on a landing page where the desk would rather
 * not advertise the minute it opened the thread and said nothing. Read from one
 * installation-wide setting, one of those two would be wrong and nothing on
 * screen would say which.
 *
 * On by default, and specifically on for a widget saved before the setting
 * existed: those rows hold `{"emoji": …, "attachments": …}` and no third key.
 */
it('answers about receipts per widget, and leaves them on for a widget that never mentioned them', function (): void {
    $quiet = Widget::factory()->create([
        'key' => 'landing',
        'bot_id' => $this->bot->getKey(),
        'features' => ['receipts' => false],
    ]);

    $older = Widget::factory()->create([
        'key' => 'older',
        'bot_id' => $this->bot->getKey(),
        'features' => ['emoji' => true, 'attachments' => false],
    ]);

    $receipts = function (string $widget): bool {
        return (bool) test()->withHeaders(['Origin' => 'https://shop.example.com'])
            ->getJson('/chatbot/site/theme?widget='.$widget)
            ->assertOk()
            ->json('features.receipts');
    };

    expect($receipts('demo'))->toBeTrue()
        ->and($receipts($older->key))->toBeTrue()
        ->and($receipts($quiet->key))->toBeFalse();
});
