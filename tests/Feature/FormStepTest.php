<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Facades\Chatbot;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Step;

beforeEach(function (): void {
    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);
});

/**
 * A graph may hold a slot as an object rather than as a key, and the node has
 * to read both.
 *
 * The settings panel writes plain keys, so a graph drawn on the canvas never
 * produces the other shape — which is exactly why this went unnoticed. A
 * scenario that arrives any other way, from a host's seeder or an import, says
 * `{"slot": "phone", "required": true}`, and casting that to a string is a
 * fatal in the middle of the bot's turn: the visitor's conversation stops
 * mid-question with no reply and no error anywhere they can see.
 */
it('asks for slots a graph stores as objects', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'name', 'required' => true],
                ['slot' => 'phone', 'required' => true],
            ],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
    ]);

    visitorSays($this->conversation, 'I want a quote');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');

    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBe('phone');

    visitorSays($this->conversation, '+7 999 123-45-67');

    expect(botSaid($this->conversation))->toContain('Got it.')
        ->and($this->conversation->refresh()->bot_slot)->toBeNull();
});

/**
 * The names the editor shows for a form's variables come from the same reading.
 *
 * The screen that lists them is where an author copies the name into the next
 * node's sentence, and it is drawn before any conversation exists — so the same
 * foreign shape took the editor down rather than the bot.
 */
it('names the variables of a form stored as objects', function (): void {
    $step = Step::factory()->make([
        'type' => 'form',
        'config' => ['slots' => [['slot' => 'name', 'required' => true], 'phone']],
    ]);

    expect(Chatbot::step('form')->writes($step))->toBe(['name', 'phone']);
});

/**
 * An optional slot nobody answers is not a failed form.
 *
 * The author marks it optional because the conversation is worth having
 * without it — a budget the visitor has not decided is the case — and the
 * "could not" exit means fetching a person. Taking that exit over the one
 * answer that did not matter is how a bot hands over a conversation it had
 * already finished.
 */
it('moves on when an optional slot stays unanswered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'name', 'required' => true],
                /*
                 * An e-mail rather than a free-text slot: free text accepts
                 * anything, so a form built on it finishes whatever the visitor
                 * types and the branch this checks is never reached. The first
                 * version of this test did exactly that and passed without the
                 * fix.
                 */
                ['slot' => 'email', 'required' => false],
            ],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');
    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBe('email');

    visitorSays($this->conversation, 'no idea, sorry');

    expect(botSaid($this->conversation))->toContain('Got it.')
        ->and(botSaid($this->conversation))->not->toContain('Let me fetch someone.')
        ->and($this->conversation->refresh()->bot_slot)->toBeNull();
});

/**
 * A slot given up on is not asked again later in the same form.
 *
 * The case only appears when something follows the optional slot: the next
 * answer walks the list again, finds the same empty slot with its attempts
 * counter freshly cleared, and asks for it a second time. The visitor sees the
 * question they have just declined come back after answering something else,
 * which is the point at which people stop writing.
 */
it('does not come back to a slot it gave up on', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'email', 'required' => false],
                ['slot' => 'name', 'required' => true],
            ],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_slot)->toBe('email');

    visitorSays($this->conversation, 'rather not say');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');

    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBeNull()
        ->and(botSaid($this->conversation))->toContain('Got it.');
});

/**
 * A required slot that never arrives still takes the "could not" exit.
 *
 * The half the optional case must not quietly take with it: a form that
 * finishes without the phone number it was added to collect produces a request
 * nobody can answer, and the branch exists to fetch a person before that
 * happens.
 */
it('takes the could-not exit when a required slot stays unanswered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [['slot' => 'email', 'required' => true]],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');
    visitorSays($this->conversation, 'no idea, sorry');

    expect(botSaid($this->conversation))->toContain('Let me fetch someone.')
        ->and(botSaid($this->conversation))->not->toContain('Got it.');
});

/**
 * Вопрос-выбор внутри анкеты приходит с кнопками.
 *
 * «Пляжный, экскурсионный или комбинированный?» уходил голым текстом: человеку
 * приходилось набирать слово в точности, а канал, рисующий кнопки у каждого
 * другого вопроса, здесь не рисовал ни одной. Нажатая кнопка приходит номером,
 * и разбор понимает его так же, как слово.
 */
it('предлагает варианты выбора кнопками', function (): void {
    Chatbot::registerSlot(
        Slot::choice('trip_type', ['beach' => 'Пляжный', 'tour' => 'Экскурсионный'])->question('Какой отдых планируете?'),
    );

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => ['slots' => ['trip_type']]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Принято: {{trip_type}}.'],
    ]);

    visitorSays($this->conversation, 'хочу отдохнуть');

    $asked = $this->conversation->messages()->where('body', 'Какой отдых планируете?')->sole();

    expect($asked->meta['options'] ?? null)->toBe(['Пляжный', 'Экскурсионный']);

    visitorSays($this->conversation, '2');

    expect(botSaid($this->conversation))->toContain('Принято: tour.');
});
