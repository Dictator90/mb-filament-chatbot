<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Steps\Types\AskStep;
use MB\FilamentChatbot\Bot\Steps\Types\JumpStep;
use MB\FilamentChatbot\Bot\Steps\Types\KeywordStep;
use MB\FilamentChatbot\Bot\Steps\Types\SetStep;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * The handful of things a node gets wrong without anybody noticing.
 *
 * Every case here ends in a conversation that looks answered: a question that
 * repeats itself politely for ever, a branch taken because one word was found
 * inside another, a value that was never written under a name a later message
 * still prints, a scenario that ends in silence instead of a person. None of
 * them throws, none of them shows up in a transcript as a failure, and all of
 * them are somebody giving up on the chat.
 */

/**
 * Builds the context a node runs in, with no engine and no conversation behind
 * it. The models are unsaved on purpose: a node reads its own row and nothing
 * else, and a test that had to persist a bot and a conversation first would be
 * testing the schema instead of the node.
 */
function nodeContext(Step $step, RecordingRuntime $runtime, ?string $said = null): StepContext
{
    return new StepContext(new Conversation, new Scenario, $step, $runtime, $said);
}

/**
 * @param  list<array{label: string, next: string}>  $options
 * @param  array<string, mixed>  $config
 */
function questionNode(array $options, array $config = []): Step
{
    return Step::factory()->make([
        'type' => 'ask',
        'body' => 'What are you after?',
        'options' => $options,
        'config' => $config,
    ]);
}

it('lists the options again and keeps waiting when nothing matched and there is no fallback', function (): void {
    $runtime = new RecordingRuntime;

    $step = questionNode([
        ['label' => 'Book a trip', 'next' => 'booking'],
        ['label' => 'Talk to a human', 'next' => 'operator'],
    ]);

    $result = app(AskStep::class)->resume(nodeContext($step, $runtime), 'сколько это стоит');

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($result->outcome)->toBe(RunStep::UNKNOWN)
        ->and($result->spoke)->toBeTrue()
        ->and($runtime->said)->toHaveCount(1)
        ->and($runtime->said[0])->toContain('1. Book a trip')
        ->and($runtime->said[0])->toContain('2. Talk to a human');
});

it('leaves through the fallback instead of asking again when one is drawn', function (): void {
    $runtime = new RecordingRuntime;

    $step = questionNode(
        [['label' => 'Book a trip', 'next' => 'booking']],
        [Step::SECOND_TARGET => 'anything-else'],
    );

    $result = app(AskStep::class)->resume(nodeContext($step, $runtime), 'сколько это стоит');

    expect($result->flow)->toBe(StepFlow::Goto)
        ->and($result->target)->toBe('anything-else')
        ->and($runtime->said)->toBe([]);
});

it('takes the option a bare number refers to, the way a channel without buttons offers them', function (): void {
    $runtime = new RecordingRuntime;

    $step = questionNode([
        ['label' => 'Book a trip', 'next' => 'booking'],
        ['label' => 'Talk to a human', 'next' => 'operator'],
    ]);

    $result = app(AskStep::class)->resume(nodeContext($step, $runtime), ' 2 ');

    expect($result->flow)->toBe(StepFlow::Goto)
        ->and($result->target)->toBe('operator')
        ->and($result->trace['choice'])->toBe('Talk to a human')
        ->and($runtime->said)->toBe([]);
});

it('matches an option label whatever the case and spacing', function (): void {
    $runtime = new RecordingRuntime;

    $step = questionNode([
        ['label' => 'Book a trip', 'next' => 'booking'],
        ['label' => 'Talk to a human', 'next' => 'operator'],
    ]);

    $result = app(AskStep::class)->resume(nodeContext($step, $runtime), '  BOOK A TRIP ');

    expect($result->flow)->toBe(StepFlow::Goto)
        ->and($result->target)->toBe('booking');
});

it('finds a keyword as a word and not inside a longer one', function (): void {
    $runtime = new RecordingRuntime;

    $step = Step::factory()->make([
        'type' => 'keyword',
        'options' => [
            ['label' => 'Refusing', 'next' => 'goodbye', 'words' => ['no'], 'when' => []],
        ],
        'config' => [Step::SECOND_TARGET => 'carry-on'],
    ]);

    $node = app(KeywordStep::class);

    $inside = $node->run(nodeContext($step, $runtime, 'I ordered a notebook last week'));
    $onItsOwn = $node->run(nodeContext($step, $runtime, 'No, thank you'));

    expect($inside->target)->toBe('carry-on')
        ->and($inside->trace['word'])->toBeNull()
        ->and($onItsOwn->target)->toBe('goodbye')
        ->and($onItsOwn->trace['word'])->toBe('no');
});

it('writes nothing when the remember node has no name', function (): void {
    $runtime = new RecordingRuntime;

    $step = Step::factory()->make([
        'type' => 'set',
        'config' => ['name' => '   ', 'value' => 'tour'],
    ]);

    $result = app(SetStep::class)->run(nodeContext($step, $runtime));

    expect($runtime->stored)->toBe([])
        ->and($result->flow)->toBe(StepFlow::Next)
        ->and($result->outcome)->toBe(RunStep::FAILED);
});

it('remembers the value under its name, with placeholders already filled in', function (): void {
    $runtime = new RecordingRuntime;
    $runtime->store('city', 'Sochi');

    $step = Step::factory()->make([
        'type' => 'set',
        'config' => ['name' => 'interest', 'value' => 'tour to {{city}}'],
    ]);

    $result = app(SetStep::class)->run(nodeContext($step, $runtime));

    expect($runtime->value('interest'))->toBe('tour to Sochi')
        ->and($result->outcome)->toBe(RunStep::PASSED);
});

it('sends a jump with no scenario to a person rather than ending the branch in silence', function (): void {
    $runtime = new RecordingRuntime;

    $step = Step::factory()->make([
        'type' => 'jump',
        'config' => [],
    ]);

    $node = app(JumpStep::class);
    $result = $node->run(nodeContext($step, $runtime));

    expect($result->flow)->toBe(StepFlow::Handoff)
        ->and($result->outcome)->toBe(RunStep::FAILED)
        ->and($node->pins($step))->toBe([])
        ->and($node->issues($step, new Scenario))->toHaveCount(1);
});

it('pins the scenario a jump depends on so a published version can freeze it', function (): void {
    $step = Step::factory()->make([
        'type' => 'jump',
        'config' => ['scenario' => 'refunds'],
    ]);

    $node = app(JumpStep::class);

    expect($node->run(nodeContext($step, new RecordingRuntime))->target)->toBe('refunds')
        ->and($node->pins($step))->toBe(['scenarios' => ['refunds']])
        ->and($node->issues($step, new Scenario))->toBe([]);
});

/**
 * Two node classes claiming one key is not an error anywhere: the registry is
 * keyed by the string, so the second registration simply replaces the first and
 * the palette is one node short. Nothing fails, and the missing node is only
 * noticed by whoever went looking for it.
 */
it('gives every registered node a key of its own, and no null among its defaults', function (): void {
    $classes = app(ChatbotRegistry::class)->stepClasses();

    expect(count(array_unique(array_values($classes))))->toBe(count($classes));

    foreach (app(ChatbotRegistry::class)->steps() as $key => $node) {
        expect($node)->toBeInstanceOf(StepType::class)
            ->and($node::key())->toBe($key)
            ->and(array_filter($node->defaults(), static fn (mixed $value): bool => $value === null))->toBe([]);
    }
});

it('registers the nine nodes every scenario is built from', function (): void {
    $registry = app(ChatbotRegistry::class);

    foreach (['start', 'say', 'ask', 'keyword', 'set', 'delay', 'jump', 'handoff', 'close'] as $key) {
        expect($registry->hasStep($key))->toBeTrue();
    }
});
