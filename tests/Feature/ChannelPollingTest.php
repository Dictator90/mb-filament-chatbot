<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * One update, in the shape Telegram hands it back from `getUpdates`.
 *
 * @return array<string, mixed>
 */
function update(int $id, string $text, int $chat = 500): array
{
    return [
        'update_id' => $id,
        'message' => [
            'message_id' => $id * 10,
            'chat' => ['id' => $chat],
            'from' => ['first_name' => 'Nina', 'username' => 'nina'],
            'text' => $text,
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $updates
 */
function updatesReply(array $updates): array
{
    return ['ok' => true, 'result' => $updates];
}

/**
 * The whole point of polling: messages arrive on a network where the provider
 * cannot reach this server at all.
 *
 * The thread is opened by the same router the webhook uses, which is what makes
 * the two paths interchangeable — an installation that switches to polling
 * keeps its conversations, its contacts and its bot's behaviour.
 */
it('fetches waiting updates and opens the thread', function (): void {
    $connection = ChannelConnection::factory()->polled()->create();

    Http::fake([
        '*/getUpdates' => Http::response(updatesReply([
            update(41, 'are you open?'),
            update(42, 'hello?'),
        ])),
    ]);

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    $conversation = Conversation::query()->sole();

    expect($conversation->bot_id)->toBe($connection->bot_id)
        ->and($conversation->channel)->toBe('telegram')
        ->and($conversation->external_id)->toBe('500')
        ->and(Message::query()->where('body', 'are you open?')->exists())->toBeTrue()
        ->and(Message::query()->where('body', 'hello?')->exists())->toBeTrue();
});

/**
 * The cursor is saved one past the highest update, and asked from next time.
 *
 * Both halves matter and both are silent when wrong. A cursor that does not
 * advance re-fetches the same questions forever, so the bot answers a customer
 * on a loop; a cursor sent as the id itself does the same thing one update
 * later. Neither raises an error anywhere.
 */
it('remembers where it stopped and asks from there', function (): void {
    $connection = ChannelConnection::factory()->polled('100')->create();

    Http::fake([
        '*/getUpdates' => Http::response(updatesReply([update(140, 'first')])),
    ]);

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    Http::assertSent(fn ($request): bool => ($request->data()['offset'] ?? null) === 100);

    expect($connection->refresh()->poll_cursor)->toBe('141')
        ->and($connection->last_event_at)->not->toBeNull();
});

/**
 * A webhook left behind makes `getUpdates` impossible, and it is always left
 * behind: polling is chosen *because* the webhook that was registered first
 * cannot deliver. Reporting Telegram's 409 instead of clearing it would
 * describe a state the person has already asked to leave.
 */
it('removes a webhook that stands in the way and fetches anyway', function (): void {
    ChannelConnection::factory()->polled()->create();

    $attempt = 0;

    Http::fake([
        '*/getUpdates' => function () use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response(['ok' => false, 'description' => 'Conflict: can\'t use getUpdates method while webhook is active'], 409)
                : Http::response(updatesReply([update(7, 'still here?')]));
        },
        '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/deleteWebhook'));

    expect(Message::query()->where('body', 'still here?')->exists())->toBeTrue();
});

/**
 * An account delivered by webhook is never asked.
 *
 * Polling it would consume its updates from the provider's queue, and Telegram
 * hands each update to one reader: the webhook would then stop delivering with
 * nothing on either side saying why.
 */
it('leaves webhook connections alone', function (): void {
    ChannelConnection::factory()->create();

    Http::fake();

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    Http::assertNothingSent();
});

/**
 * A connection switched off is not polled either.
 *
 * Off means its owner has said this account must not answer, and fetching for
 * it would both empty the provider's queue and hand the questions to a bot
 * nobody asked to run.
 */
it('leaves a switched-off connection alone', function (): void {
    ChannelConnection::factory()->polled()->inactive()->create();

    Http::fake();

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    Http::assertNothingSent();
});

/**
 * One account's refusal is written onto its own row, and the pass goes on.
 *
 * The failure put every other bot on the installation behind the
 * worst-configured one — and the reason belongs on the connection because the
 * screen that asks "is this working" is the bot's Channels tab, not a log file
 * on the server.
 */
it('records a refusal and keeps polling the other accounts', function (): void {
    $broken = ChannelConnection::factory()->polled('5')->create([
        'credentials' => ['token' => 'revoked', 'secret' => 's'],
    ]);
    $working = ChannelConnection::factory()->polled()->create([
        'credentials' => ['token' => 'good', 'secret' => 's'],
    ]);

    Http::fake([
        '*/botrevoked/getUpdates' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        '*/botgood/getUpdates' => Http::response(updatesReply([update(3, 'anyone there?')])),
    ]);

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    expect($broken->refresh()->last_error)->toBe('Unauthorized')
        ->and($broken->poll_cursor)->toBe('5')
        ->and($working->refresh()->poll_cursor)->toBe('4')
        ->and(Message::query()->where('body', 'anyone there?')->exists())->toBeTrue();
});

/**
 * The fetched update is decoded by the code the webhook uses.
 *
 * A button press is the case that proves it: its answer is the option's
 * position carried in `callback_data`, decoded nowhere else. A second decoder
 * for polling would get this wrong on its first day and the symptom would be a
 * bot that ignores every button.
 */
it('reads a button press the same way a delivered one is read', function (): void {
    ChannelConnection::factory()->polled()->create();

    Http::fake([
        '*/getUpdates' => Http::response(updatesReply([[
            'update_id' => 9,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['first_name' => 'Nina'],
                'message' => ['message_id' => 90, 'chat' => ['id' => 777]],
                'data' => '2',
            ],
        ]])),
    ]);

    $this->artisan('chatbot:poll', ['--once' => true, '--wait' => 1])->assertSuccessful();

    $message = Message::query()->where('body', '2')->sole();

    expect($message->external_id)->toBe('callback:cb-1')
        ->and(Conversation::query()->sole()->external_id)->toBe('777');
});
