<?php

declare(strict_types=1);

use MB\FilamentChatbot\Chat\OperatorReply;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Models\Conversation;
use Workbench\App\Models\User;

/**
 * The reply is what silences the bot, and nothing on screen says so.
 *
 * `BotEngine` keeps quiet while a conversation has an owner or has been handed
 * off. If answering did not set both, the bot would carry on running its
 * scenario over the operator's reply — the customer gets two answers to one
 * question, from two different voices, and the transcript is the only place it
 * shows.
 */
it('stops the bot from answering over an operator who has replied', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->botRunning()->create();

    app(OperatorReply::class)->send($conversation, 'Let me check that for you.', $user);

    $conversation->refresh();

    expect($conversation->assigned_to_id)->toBe($user->getKey())
        ->and($conversation->bot_state)->toBe(BotState::HandedOff);

    expect(visitorSays($conversation, 'thanks'))->toBeFalse();
});

/**
 * The author is not decoration: reports count operator work by it, and the
 * engine's own silence rule reads it. An operator reply filed as anything else
 * looks like the bot answered.
 */
it('files an operator reply as outgoing, authored by the person who typed it', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create();

    $message = app(OperatorReply::class)->send($conversation, 'On its way.', $user);

    expect($message->author)->toBe(MessageAuthor::Operator)
        ->and($message->isIncoming())->toBeFalse()
        ->and($message->user_id)->toBe($user->getKey())
        ->and($message->body)->toBe('On its way.');
});

/**
 * An empty body would be delivered as a real message — a notification with
 * nothing in it — and would leave the operator looking at their own blank
 * bubble believing they had answered.
 */
it('refuses to send a reply with nothing in it', function (): void {
    $conversation = Conversation::factory()->create();

    expect(fn () => app(OperatorReply::class)->send($conversation, "  \n ", User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);

    expect($conversation->messages()->count())->toBe(0);
});

/**
 * Helping out on a colleague's conversation must not quietly transfer it: the
 * owner is who the rest of the desk routes by, and a stolen one takes the
 * conversation off the colleague's own list without anybody being told.
 */
it('leaves a conversation with the colleague who already owns it', function (): void {
    $owner = User::factory()->create();
    $helper = User::factory()->create();
    $conversation = Conversation::factory()->create(['assigned_to_id' => $owner->getKey()]);

    app(OperatorReply::class)->send($conversation, 'Adding a note.', $helper);

    expect($conversation->refresh()->assigned_to_id)->toBe($owner->getKey());
});
