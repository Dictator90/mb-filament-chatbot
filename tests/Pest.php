<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Tests\TestCase;
use Workbench\App\Models\User;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Signs somebody in the way the panel signs people in, and no other way.
 *
 * **Not `actingAs($user, 'workbench')`.** That helper also calls
 * `shouldUse('workbench')`, which makes the panel's guard the application
 * default for the rest of the test — and the default guard is the one thing
 * this is here to leave alone. Under `actingAs` a package that reads
 * `auth()->user()` looks perfectly correct, because the default it reads has
 * been quietly pointed at the panel. Nothing outside a test ever does that.
 *
 * What a real request looks like is this: the panel authenticates on its own
 * guard, the application default stays where the host put it, and the two
 * resolve different people — or, more often, one person and nobody. So the user
 * is set on the panel's guard alone.
 *
 * A panel on its own guard is ordinary, not exotic: staff sign in against one
 * table and customers against another, which is what `->authGuard()` is for.
 * Against such a host every `auth()->user()` in a package answers `null` while
 * somebody is plainly signed in, and every consequence is silent — a screen
 * vanishes from the menu, a badge counts a guest, "mine" matches nothing, a
 * reply is filed with no author. Nothing throws.
 */
function signIn(?User $user = null): User
{
    $user ??= User::factory()->create();

    auth()->guard('workbench')->setUser($user);

    return $user;
}

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
