<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Sends a message from the visitor and lets the bot take its turn.
 *
 * Here rather than in one test file because two suites drive whole
 * conversations — the engine's own and the demo data's — and a second copy of
 * this would be a second definition of what "the visitor said something" means.
 * The engine reads the conversation from the database on every turn, so the
 * refresh is part of the helper: passing a stale model is how a test ends up
 * asserting against the state of two turns ago.
 */
function visitorSays(Conversation $conversation, string $body): bool
{
    $message = Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Incoming,
        'author' => MessageAuthor::Visitor,
        'body' => $body,
    ]);

    return app(BotEngine::class)->handle($conversation->refresh(), $message);
}

/**
 * Everything the bot has said in this conversation, in order.
 *
 * @return list<string>
 */
function botSaid(Conversation $conversation): array
{
    return $conversation->messages()
        ->where('direction', MessageDirection::Outgoing)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}
