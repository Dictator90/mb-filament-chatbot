<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Jobs\ContinueScenario;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;

beforeEach(function (): void {
    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);
});

it('walks a scenario from its start node to the end and answers the visitor', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'greet', 'type' => 'say', 'body' => 'We open at nine.', 'next_key' => 'bye'],
        ['key' => 'bye', 'type' => 'close', 'body' => 'Anything else?'],
    ]);

    expect(visitorSays($this->conversation, 'what are your hours?'))->toBeTrue()
        ->and(botSaid($this->conversation))->toBe(['We open at nine.', 'Anything else?'])
        ->and($this->conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

/**
 * The two-entry contract of a waiting node. Getting this wrong is not subtle in
 * production and is completely silent in a test that only checks the first turn:
 * the node asks, the visitor answers, and the bot asks again.
 */
it('waits for an answer and then branches on it', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'pick', 'config' => ['trigger' => 'fallback']],
        ['key' => 'pick', 'type' => 'ask', 'body' => 'Sales or support?', 'options' => [
            ['label' => 'Sales', 'next' => 'sales'],
            ['label' => 'Support', 'next' => 'support'],
        ]],
        ['key' => 'sales', 'type' => 'say', 'body' => 'Putting you through to sales.'],
        ['key' => 'support', 'type' => 'say', 'body' => 'Support here.'],
    ]);

    visitorSays($this->conversation, 'hello there');

    expect($this->conversation->refresh()->bot_step_key)->toBe('pick')
        ->and(botSaid($this->conversation))->toBe(['Sales or support?']);

    visitorSays($this->conversation, 'support');

    expect(botSaid($this->conversation))->toBe(['Sales or support?', 'Support here.'])
        ->and($this->conversation->refresh()->bot_step_key)->toBeNull();
});

/**
 * The buttons reach the message, and the branch rules never do.
 *
 * A question's options are drawn by the channel — buttons in the widget, a
 * numbered list where buttons do not exist — and every one of those readers was
 * written and waiting while nothing ever wrote them. `Runtime::say()` took text
 * and nothing else, so `meta.options` was never set on any message ever sent:
 * `ChannelText::options()` answered "no options" forever, the widget's `choices`
 * endpoint returned an empty list on every poll, and a question with three
 * buttons arrived on every channel as a bare line with nothing to press. It is
 * invisible from the server, because the bot said exactly what it was told to.
 *
 * The second half matters as much. A `condition` carries a list of options too,
 * and those are the author's private routing rules; attaching them the same way
 * would print "We have a number" on a customer's screen as if it were something
 * to choose.
 */
it('sends a question with the answers it offers, and a branch rule with none', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'pick', 'config' => ['trigger' => 'fallback']],
        ['key' => 'pick', 'type' => 'ask', 'body' => 'Sales or support?', 'options' => [
            ['label' => 'Sales', 'next' => 'sales'],
            ['label' => 'Support', 'next' => 'support'],
        ]],
        ['key' => 'sales', 'type' => 'say', 'body' => 'Putting you through to sales.'],
        ['key' => 'support', 'type' => 'say', 'body' => 'Support here.'],
    ]);

    visitorSays($this->conversation, 'hello there');

    expect($this->conversation->refresh()->pendingChoices())
        ->toBe([['label' => 'Sales'], ['label' => 'Support']]);

    visitorSays($this->conversation, 'support');

    expect($this->conversation->refresh()->pendingChoices())->toBe([]);
});

/**
 * A pause must not hold the request. Asserting the job is dispatched rather than
 * that the delay "worked" is the difference between a bot that is polite and one
 * that occupies a worker for three seconds per conversation.
 */
it('schedules a pause instead of sleeping through it', function (): void {
    Queue::fake();

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'wait', 'config' => ['trigger' => 'fallback']],
        ['key' => 'wait', 'type' => 'delay', 'next_key' => 'after', 'config' => ['seconds' => 3]],
        ['key' => 'after', 'type' => 'say', 'body' => 'Still here.'],
    ]);

    visitorSays($this->conversation, 'hello');

    Queue::assertPushed(ContinueScenario::class);

    expect(botSaid($this->conversation))->toBe([])
        ->and($this->conversation->refresh()->bot_step_key)->toBe('wait');
});

it('picks the pause back up at the node after it, never at the pause itself', function (): void {
    $scenario = scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'wait', 'config' => ['trigger' => 'fallback']],
        ['key' => 'wait', 'type' => 'delay', 'next_key' => 'after', 'config' => ['seconds' => 1]],
        ['key' => 'after', 'type' => 'say', 'body' => 'Still here.'],
    ]);

    Queue::fake();
    visitorSays($this->conversation, 'hello');
    Queue::swap(app('queue'));

    app(BotEngine::class)->resumeAfterDelay($this->conversation->refresh(), 'wait');

    expect(botSaid($this->conversation))->toBe(['Still here.'])
        ->and($scenario->fresh()->id)->not->toBeNull();
});

/**
 * Silence after a handover is the rule that protects an operator mid-sentence.
 */
it('falls silent once the conversation has been handed to a person', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'human', 'config' => ['trigger' => 'fallback']],
        ['key' => 'human', 'type' => 'handoff', 'body' => 'One moment.'],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff);

    expect(visitorSays($this->conversation, 'are you there?'))->toBeFalse()
        ->and(botSaid($this->conversation))->toBe(['One moment.']);
});

it('stays silent while somebody has the conversation, even without a handover', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'Hello.'],
    ]);

    $this->conversation->forceFill(['assigned_to_id' => 1])->save();

    expect(visitorSays($this->conversation, 'hello'))->toBeFalse()
        ->and(botSaid($this->conversation))->toBe([]);
});

/**
 * A node kind whose package has been removed must not take the conversation with
 * it. Guessing what it meant is the one thing that would be worse than stopping.
 */
it('sends the conversation to a person when a node kind is not registered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'gone', 'config' => ['trigger' => 'fallback']],
        ['key' => 'gone', 'type' => 'crm.long_removed', 'next_key' => null],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff)
        ->and(Run::query()->first()->outcome)->toBe(RunOutcome::Failed);
});

/**
 * A loop guard, verified as a property rather than a count: the walk stops and
 * the visitor reaches a person, whatever the exact budget happens to be.
 */
it('stops a scenario that walks in a circle and fetches a person', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'a', 'config' => ['trigger' => 'fallback']],
        ['key' => 'a', 'type' => 'set', 'next_key' => 'b', 'config' => ['name' => 'x', 'value' => '1']],
        ['key' => 'b', 'type' => 'set', 'next_key' => 'a', 'config' => ['name' => 'y', 'value' => '2']],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff)
        ->and(Run::query()->first()->outcome)->toBe(RunOutcome::StepLimit);
});

/**
 * An unconnected output is the commonest unfinished scenario there is, and what
 * the visitor experiences depends entirely on whether anything was said first.
 */
it('fetches a person when a branch ends before the bot has said anything', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'nowhere', 'config' => ['trigger' => 'fallback']],
        ['key' => 'nowhere', 'type' => 'set', 'next_key' => null, 'config' => ['name' => 'x', 'value' => '1']],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff)
        ->and(Run::query()->first()->outcome)->toBe(RunOutcome::DeadEnd);
});

it('leaves the conversation alone when a branch ends after the bot has answered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'answer', 'config' => ['trigger' => 'fallback']],
        ['key' => 'answer', 'type' => 'say', 'body' => 'Nine to five.', 'next_key' => null],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_state)->not->toBe(BotState::HandedOff)
        ->and(botSaid($this->conversation))->toBe(['Nine to five.']);
});

/**
 * The whole point of call over jump. Without the return, shared work has to be
 * copied into every scenario that needs it, and the copies drift.
 */
it('calls another scenario and comes back with what it returned', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'ask_them', 'config' => ['trigger' => 'fallback']],
        ['key' => 'ask_them', 'type' => 'call', 'next_key' => 'thanks', 'config' => ['scenario' => 'identify', 'into' => 'who']],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Thanks, {{who}}.'],
    ], ['slug' => 'main']);

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'done', 'config' => ['trigger' => 'call']],
        ['key' => 'done', 'type' => 'result', 'config' => ['value' => 'Alex']],
    ], ['slug' => 'identify']);

    visitorSays($this->conversation, 'hello');

    expect(botSaid($this->conversation))->toBe(['Thanks, Alex.'])
        ->and($this->conversation->refresh()->bot_answers)->toMatchArray(['who' => 'Alex'])
        ->and($this->conversation->bot_stack)->toBeNull();
});

/**
 * A scenario has to be runnable on its own as well as callable, or an author
 * ends up maintaining two endings that drift apart.
 */
it('treats a result node reached outside a call as the end of the conversation', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'done', 'config' => ['trigger' => 'fallback']],
        ['key' => 'done', 'type' => 'result', 'config' => ['value' => 'nothing to return to']],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

/**
 * Asked for a person, gets a person — before any scenario has a chance to walk
 * them through three more nodes first.
 */
it('hands over immediately when the visitor asks for a human', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'Hello.'],
    ]);

    visitorSays($this->conversation, 'let me talk to a human please');

    /*
     * One line, and not the scenario's: the greeting never runs. The line used
     * to be missing altogether, and asking for a person was answered with
     * silence.
     */
    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff)
        ->and(botSaid($this->conversation))->toBe([__('chatbot::bot.handing_off')]);
});

/**
 * На русской установке «менеджер» тоже зовёт человека.
 *
 * Слова передачи жили в конфиге одним английским набором, и «позовите
 * менеджера» уходило в сценарий: человек получал ещё один ответ бота
 * вместо менеджера. Нашёл это аудит — жалобы не было, потому что заметить
 * такое изнутри разговора нельзя.
 */
it('зовёт человека по русскому «менеджер»', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'Здравствуйте.'],
    ]);

    visitorSays($this->conversation, 'позовите менеджера, пожалуйста');

    expect($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff);
});

/**
 * Scenarios are edited while people are mid-conversation, and the node somebody
 * is waiting on can simply stop existing. Losing their message is the one
 * outcome that must not happen.
 */
it('starts over rather than losing a message when the node being waited on is gone', function (): void {
    $scenario = scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'Hello again.'],
    ]);

    $this->conversation->forceFill([
        'bot_scenario_id' => $scenario->getKey(),
        'bot_step_key' => 'a_node_that_was_deleted',
    ])->save();

    visitorSays($this->conversation, 'anyone there?');

    expect(botSaid($this->conversation))->toBe(['Hello again.']);
});

/**
 * The journal is what people read when they ask why the bot said something, and
 * a run with no steps in it answers nothing.
 */
it('records every node it walked, in order', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'greet', 'config' => ['trigger' => 'fallback']],
        ['key' => 'greet', 'type' => 'say', 'body' => 'Hello.', 'next_key' => 'bye'],
        ['key' => 'bye', 'type' => 'close', 'body' => 'Bye.'],
    ]);

    visitorSays($this->conversation, 'hello');

    $trace = Run::query()->first()->steps()->pluck('step_key')->all();

    expect($trace)->toBe(['start', 'greet', 'bye']);
});

/**
 * Подтверждение предлагает варианты, а не печатает их списком.
 *
 * В Telegram узел уходил простым текстом «1. Да, оформляйте / 2. Нет, поправлю»:
 * кнопок не было, человек отвечал словами — «Оформляйте. Вы можете отель
 * подобрать?» — и получал «не понял ответ» на согласие, которое только что дал.
 * Варианты едут свойством сообщения, и канал рисует их как умеет; канал без
 * кнопок по-прежнему получит нумерованный список, но уже от себя.
 */
it('отдаёт варианты подтверждения как варианты ответа', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'confirm', 'config' => ['trigger' => 'fallback']],
        ['key' => 'confirm', 'type' => 'approval', 'body' => 'Оформляю заявку. Всё верно?', 'config' => [
            'yes_label' => 'Да, оформляйте',
            'no_label' => 'Нет, поправлю',
            'else_key' => 'sorry',
        ], 'next_key' => 'done'],
        ['key' => 'done', 'type' => 'say', 'body' => 'Готово.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Поправим.'],
    ]);

    visitorSays($this->conversation, 'хочу заявку');

    $asked = $this->conversation->messages()
        ->where('direction', MessageDirection::Outgoing)
        ->orderByDesc('id')
        ->first();

    expect($asked?->body)->toBe('Оформляю заявку. Всё верно?')
        ->and($asked?->meta['options'] ?? null)->toBe(['Да, оформляйте', 'Нет, поправлю'])
        /* Второй репликой список вариантов больше не присылается. */
        ->and(botSaid($this->conversation))->toHaveCount(1);

    /* Нажатая кнопка приходит номером — и читается как согласие. */
    visitorSays($this->conversation, '1');

    expect(botSaid($this->conversation))->toContain('Готово.');
});
