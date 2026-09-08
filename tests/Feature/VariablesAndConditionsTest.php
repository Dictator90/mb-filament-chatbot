<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Bot\BotRuntime;
use MB\FilamentChatbot\Bot\Conditions\ConditionEvaluator;
use MB\FilamentChatbot\Bot\RunJournal;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\ConditionStep;
use MB\FilamentChatbot\Bot\Variables\Variables;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

function runtimeFor(Conversation $conversation, ?string $said = null): BotRuntime
{
    return new BotRuntime($conversation, app(Variables::class), new RunJournal, $said);
}

function conditionOn(array $branches, Conversation $conversation, ?string $said = null): StepFlow
{
    $scenario = Scenario::factory()->create(['bot_id' => null]);

    $step = Step::factory()->make([
        'scenario_id' => $scenario->getKey(),
        'key' => 'branch',
        'type' => 'condition',
        'options' => $branches,
        'config' => [Step::SECOND_TARGET => 'otherwise'],
    ]);

    $context = new StepContext($conversation, $scenario, $step, runtimeFor($conversation, $said), $said);

    return app(ConditionStep::class)->run($context)->flow;
}

beforeEach(function (): void {
    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);
});

/**
 * A visitor who receives "Hello {{ first_name }}" has been shown the inside of
 * the scenario. A gap in the sentence is worse for nobody and is noticed by the
 * author on the first test message.
 */
it('renders an unknown variable as a gap, never as markup', function (): void {
    $filled = app(Variables::class)->fill('Hello {{ first_name }}!', $this->conversation);

    expect($filled)->toBe('Hello !');
});

/**
 * Substitution is not recursive, and this is a boundary rather than an
 * optimisation: a stored value is something the visitor typed, and expanding it
 * would hand them a way to read variables the scenario never offered them.
 */
it('does not expand placeholders that arrive inside a stored value', function (): void {
    $this->conversation->forceFill([
        'bot_answers' => ['note' => 'see {{ secret }}', 'secret' => 'internal'],
    ])->save();

    expect(app(Variables::class)->fill('{{ note }}', $this->conversation->refresh()))
        ->toBe('see {{ secret }}');
});

/**
 * "Never asked" and "answered with nothing" are different situations, and only
 * one of them is worth asking about again.
 */
it('tells an unasked slot apart from one answered with nothing', function (): void {
    $this->conversation->forceFill(['bot_answers' => ['email' => '']])->save();
    $conversation = $this->conversation->refresh();
    $runtime = runtimeFor($conversation);
    $evaluator = app(ConditionEvaluator::class);

    $answeredBlank = $evaluator->evaluate(
        ['field' => 'variable', 'name' => 'email', 'operator' => 'blank'],
        $conversation,
        $runtime,
    );

    $neverAsked = $evaluator->evaluate(
        ['field' => 'variable', 'name' => 'phone', 'operator' => 'filled'],
        $conversation,
        $runtime,
    );

    expect($answeredBlank)->toBeTrue()
        ->and($neverAsked)->toBeFalse();
});

/**
 * The rule the whole evaluator exists for. A condition naming a field whose
 * package has been removed is a fault in the scenario, but it surfaces
 * mid-conversation with a customer waiting — so the branch does not fire, the
 * conversation carries on, and the fault is visible to the person who can fix it.
 */
it('sends a branch with an unrecognised field down the otherwise route instead of failing', function (): void {
    $flow = conditionOn([
        ['label' => 'gone', 'next' => 'somewhere', 'when' => [
            'field' => 'crm.long_removed', 'operator' => 'yes',
        ]],
    ], $this->conversation);

    expect($flow)->toBe(StepFlow::Second);
});

/**
 * The operator has to be one the field's own kind offers, and the check has to
 * live in the evaluator rather than in each field.
 *
 * "Filled" against a yes/no field is the case that makes this visible: a flag
 * answers '1' or '0', both of which are filled, so without the guard the branch
 * fires for every visitor whether they are identified or not. Most wrong
 * operators simply fail to match and hide the missing guard entirely.
 */
it('refuses an operator the field never offered, even one that would have matched', function (): void {
    $answered = app(ConditionEvaluator::class)->evaluate(
        ['field' => 'identified', 'operator' => 'filled'],
        $this->conversation,
        runtimeFor($this->conversation),
    );

    expect($answered)->toBeFalse();
});

it('refuses a field that needs a name and was not given one', function (): void {
    $answered = app(ConditionEvaluator::class)->evaluate(
        ['field' => 'variable', 'operator' => 'filled'],
        $this->conversation,
        runtimeFor($this->conversation),
    );

    expect($answered)->toBeFalse();
});

/**
 * A night shift is a window that crosses midnight, and the naive comparison
 * — after the start and before the end — is false at every hour of it.
 */
it('gets working hours right for a window that crosses midnight', function (): void {
    $bot = Bot::factory()->create(['hours_from' => '22:00', 'hours_till' => '06:00']);
    $this->conversation->forceFill(['bot_id' => $bot->getKey()])->save();
    $conversation = $this->conversation->refresh();

    $evaluator = app(ConditionEvaluator::class);
    $when = ['field' => 'working_hours', 'operator' => 'yes'];

    $this->travelTo(now()->setTime(23, 30));
    $atNight = $evaluator->evaluate($when, $conversation, runtimeFor($conversation));

    $this->travelTo(now()->setTime(4, 0));
    $beforeDawn = $evaluator->evaluate($when, $conversation, runtimeFor($conversation));

    $this->travelTo(now()->setTime(13, 0));
    $atNoon = $evaluator->evaluate($when, $conversation, runtimeFor($conversation));

    expect($atNight)->toBeTrue()
        ->and($beforeDawn)->toBeTrue()
        ->and($atNoon)->toBeFalse();
});

/**
 * A property, not a query budget: three branches asking about the turn count
 * cost what one costs. A ceiling would only catch a regression in the count.
 */
it('answers repeated questions about the turn count without asking the database again', function (): void {
    Message::create([
        'conversation_id' => $this->conversation->getKey(),
        'direction' => MessageDirection::Incoming,
        'author' => MessageAuthor::Visitor,
        'body' => 'hello',
    ]);

    $conversation = $this->conversation->refresh();
    $runtime = runtimeFor($conversation);
    $evaluator = app(ConditionEvaluator::class);
    $when = ['field' => 'turns', 'operator' => 'at_least', 'value' => 1];

    $evaluator->evaluate($when, $conversation, $runtime);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $evaluator->evaluate($when, $conversation, $runtime);
    $evaluator->evaluate($when, $conversation, $runtime);
    $evaluator->evaluate($when, $conversation, $runtime);

    expect($queries)->toBe(0);
});

/**
 * First match wins, in the order the author put them in. Any other rule makes
 * the order on the canvas mean something other than what it looks like.
 */
it('takes the first branch that matches, not the last', function (): void {
    $this->conversation->forceFill(['bot_answers' => ['plan' => 'pro']])->save();

    $scenario = Scenario::factory()->create(['bot_id' => null]);

    $step = Step::factory()->make([
        'scenario_id' => $scenario->getKey(),
        'key' => 'branch',
        'type' => 'condition',
        'options' => [
            ['label' => 'first', 'next' => 'one', 'when' => [
                'field' => 'variable', 'name' => 'plan', 'operator' => 'filled',
            ]],
            ['label' => 'second', 'next' => 'two', 'when' => [
                'field' => 'variable', 'name' => 'plan', 'operator' => 'equals', 'value' => 'pro',
            ]],
        ],
        'config' => [Step::SECOND_TARGET => 'otherwise'],
    ]);

    $conversation = $this->conversation->refresh();
    $context = new StepContext($conversation, $scenario, $step, runtimeFor($conversation), null);

    $result = app(ConditionStep::class)->run($context);

    expect($result->flow)->toBe(StepFlow::Goto)
        ->and($result->target)->toBe('one');
});

/**
 * "Linked to a record" and "identified" are different questions, and a scenario
 * that treats them as one is wrong in both directions.
 *
 * A thread an operator filed against a customer card, or one the host's
 * directory recognised by phone number, is linked and unproven: good enough to
 * fetch an order's status, nowhere near good enough to read somebody their own
 * invoice. And a visitor whose identity the site signed is proven and unlinked
 * whenever the host keeps no record of them.
 */
it('tells being linked to a record apart from being identified', function (): void {
    $conversation = Conversation::factory()->create([
        'contact_type' => 'demo_record',
        'contact_id' => 42,
        'identified_at' => null,
    ]);

    $linked = fn (string $field): bool => app(ConditionEvaluator::class)->evaluate(
        ['field' => $field, 'operator' => 'yes'],
        $conversation,
        runtimeFor($conversation),
    );

    expect($linked('has_contact'))->toBeTrue()
        ->and($linked('identified'))->toBeFalse();

    $signed = Conversation::factory()->create(['identified_at' => now()]);

    $proven = fn (string $field): bool => app(ConditionEvaluator::class)->evaluate(
        ['field' => $field, 'operator' => 'yes'],
        $signed,
        runtimeFor($signed),
    );

    expect($proven('identified'))->toBeTrue()
        ->and($proven('has_contact'))->toBeFalse();
});

/**
 * A cookie the placement declared, branched on by name.
 *
 * Named rather than fixed, like a collected variable: which cookies exist is
 * the site's business, and the package cannot have a condition per campaign.
 */
it('branches on a cookie the page handed over', function (): void {
    $conversation = Conversation::factory()->create([
        'meta' => ['cookies' => ['utm_campaign' => 'spring-sale']],
    ]);

    $answers = fn (string $name, string $value): bool => app(ConditionEvaluator::class)->evaluate(
        ['field' => 'cookie', 'name' => $name, 'operator' => 'equals', 'value' => $value],
        $conversation,
        runtimeFor($conversation),
    );

    expect($answers('utm_campaign', 'spring-sale'))->toBeTrue()
        ->and($answers('utm_campaign', 'winter-sale'))->toBeFalse()
        ->and($answers('plan', 'spring-sale'))->toBeFalse();
});
