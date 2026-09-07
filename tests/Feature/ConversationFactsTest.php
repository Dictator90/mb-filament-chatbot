<?php

declare(strict_types=1);

use MB\FilamentChatbot\Chat\ConversationFacts;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * A collected answer is a scenario variable: it lives for the run and is gone
 * afterwards. That is right for "which order number" and wrong for the three
 * things the rest of the application asks about a thread — who this is, and how
 * to reach them.
 */
function collectingBot(string $rememberAs): Bot
{
    $bot = Bot::factory()->create();

    $scenario = Scenario::factory()->create([
        'bot_id' => $bot->getKey(),
        'slug' => 'greet',
        'is_active' => true,
        'priority' => 50,
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'ask',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'ask',
        'type' => 'collect',
        'next_key' => null,
        'config' => array_filter([
            'slot' => 'name',
            'into' => 'who',
            'remember_as' => $rememberAs,
        ]),
    ]);

    return $bot;
}

/**
 * Without the field the answer stays a variable, and the desk goes on showing a
 * number to an operator who is reading the visitor's name three lines below it.
 * With it, the thread knows who it is talking to.
 */
it('writes a collected answer onto the conversation only when the node says to', function (): void {
    /*
     * Started nameless on purpose. The factory fills a display name, and a
     * conversation that arrived with one is the case the *next* test is about —
     * here the question is what happens to a thread that knows nothing yet.
     */
    $quiet = Conversation::factory()->create([
        'bot_id' => collectingBot('')->getKey(),
        'display_name' => null,
    ]);

    visitorSays($quiet, 'hello');
    visitorSays($quiet, 'Alexandra');

    expect($quiet->refresh()->display_name)->toBeNull();

    $naming = Conversation::factory()->create([
        'bot_id' => collectingBot('name')->getKey(),
        'display_name' => null,
    ]);

    visitorSays($naming, 'hello');
    visitorSays($naming, 'Alexandra');

    expect($naming->refresh()->display_name)->toBe('Alexandra')
        ->and($naming->title())->toBe('Alexandra');
});

/**
 * What the channel already knew is never overwritten by what somebody typed.
 *
 * A name that arrived with the conversation is a fact — a signed identity the
 * host issued, a Telegram profile — where an answer typed into a chat box is a
 * claim. Taking the claim would let anybody rename the signed-in customer whose
 * thread this is, and the operator would have no way of telling which one they
 * were looking at.
 */
it('never lets a typed answer overwrite what the channel knew', function (): void {
    $conversation = Conversation::factory()->create([
        'bot_id' => collectingBot('name')->getKey(),
        'display_name' => 'Alexandra Ivanova',
    ]);

    visitorSays($conversation, 'hello');
    visitorSays($conversation, 'Santa Claus');

    expect($conversation->refresh()->display_name)->toBe('Alexandra Ivanova');
});

/**
 * The vocabulary is closed. A scenario may fill the three fields the package
 * itself reads and nothing else — a conversation that could be written to
 * anywhere becomes a scratch pad the next release cannot change.
 */
it('writes only the fields it names, and refuses anything else', function (): void {
    $facts = new ConversationFacts;
    $conversation = Conversation::factory()->create([
        'display_name' => null,
        'contact_phone' => null,
        'contact_email' => null,
    ]);

    expect($facts->remember($conversation, 'phone', '+7 900 000-00-00'))->toBeTrue()
        ->and($facts->remember($conversation, 'email', 'a@example.test'))->toBeTrue()
        ->and($facts->remember($conversation, 'assigned_to_id', '1'))->toBeFalse()
        ->and($facts->remember($conversation, 'name', '   '))->toBeFalse();

    $conversation->refresh();

    expect($conversation->contact_phone)->toBe('+7 900 000-00-00')
        ->and($conversation->contact_email)->toBe('a@example.test')
        ->and($conversation->assigned_to_id)->toBeNull();
});
