<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\Types\AnswerStep;
use MB\FilamentChatbot\Demo\DemoData;
use MB\FilamentChatbot\Knowledge\KnowledgeFinder;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * The knowledge base fails in ways a transcript reads as success.
 *
 * A topic matched on the one word it shares with half the base answers the
 * wrong question confidently; a topic the company decided never to answer from
 * a bot answers it anyway; a question that fit two topics equally is answered
 * from whichever the database happened to return first. None of that throws,
 * and all of it reaches a customer as a sentence they will quote back.
 */
beforeEach(function (): void {
    $this->bot = app(DemoData::class)->seed();
});

function knowledgeFinder(): KnowledgeFinder
{
    return app(KnowledgeFinder::class);
}

/**
 * How many queries a closure runs, with a warm-up first.
 *
 * The warm-up is not optional. The first call of a turn pays for things that
 * have nothing to do with the knowledge base — a lazily booted connection, a
 * schema lookup — and counting them would make the second reading differ from
 * the first for reasons no change to this code could ever fix.
 */
function knowledgeQueries(Closure $work): int
{
    $work();

    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    $work();

    return $count;
}

it('lets a rare root outweigh one that half the base shares', function (): void {
    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'where is my refund for this order');

    expect($verdict)->not->toBeNull()
        ->and($verdict->entry()->topic_key)->toBe('refund-status');
});

/**
 * Every word below appears in the demo topics' own questions — "how" in four of
 * them, "can" and "you" in two apiece — so a matcher that skipped the stop-word
 * list would not merely score badly here, it would find a topic and answer it.
 */
it('finds nothing in a question made of nothing but stop words', function (): void {
    expect(knowledgeFinder()->find($this->bot->getKey(), 'how can you do this?'))
        ->toBeNull();
});

it('refuses to choose between two topics that fit the question equally well', function (): void {
    /*
     * Two topics written on purpose to be indistinguishable from the wording
     * below: the word that would separate them — refill or replace — is exactly
     * the word the visitor did not write.
     */
    foreach (['battery-refill', 'battery-replace'] as $key) {
        KnowledgeEntry::factory()->create([
            'bot_id' => $this->bot->getKey(),
            'topic_key' => $key,
            'question' => 'What about the battery pack?',
            'keywords' => ['battery pack'],
            'scenario_slug' => null,
        ]);
    }

    expect(knowledgeFinder()->find($this->bot->getKey(), 'battery pack'))->toBeNull();
});

/**
 * Runs the answer node against a question, with no engine behind it.
 *
 * @param  array<string, mixed>  $config
 */
function answersTo(int $botId, string $said, RecordingRuntime $runtime, array $config = []): StepResult
{
    $context = new StepContext(
        new Conversation(['bot_id' => $botId]),
        new Scenario,
        Step::factory()->make(['type' => 'answer', 'config' => $config]),
        $runtime,
        $said,
    );

    return app(AnswerStep::class)->run($context);
}

/**
 * A refund topic exists in the demo precisely so that it can be found and not
 * spoken. The assertion is on what the visitor heard, not on the branch alone:
 * a node that took the second exit and still sent the text would look correct
 * in the journal and be a promise about somebody's money in the chat.
 */
it('never speaks a topic the company decided the bot may not answer', function (): void {
    KnowledgeEntry::query()
        ->where('topic_key', 'refund-status')
        ->update(['answer' => 'Refunds land within three working days.']);

    $runtime = new RecordingRuntime;

    $result = answersTo($this->bot->getKey(), 'where is my refund for this order', $runtime);

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->spoke)->toBeFalse()
        ->and($runtime->said)->toBe([])
        ->and($result->trace['topic'])->toBe('refund-status');
});

it('starts the scenario a topic names instead of answering it', function (): void {
    $runtime = new RecordingRuntime;

    $result = answersTo($this->bot->getKey(), 'track my parcel', $runtime);

    expect($result->flow)->toBe(StepFlow::Jump)
        ->and($result->target)->toBe('order-status')
        ->and($runtime->said)->toBe([]);
});

it('sends the recorded text of a topic it may answer', function (): void {
    $runtime = new RecordingRuntime;

    $result = answersTo($this->bot->getKey(), 'how long does delivery take', $runtime);

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($result->outcome)->toBe(RunStep::ANSWERED)
        ->and($result->spoke)->toBeTrue()
        ->and($runtime->said)->toHaveCount(1)
        ->and($runtime->said[0])->toContain('two to four working days');
});

/**
 * The material the model answers from comes out of the real base, not a stand-in.
 *
 * Both AI suites bind a fake `KnowledgeSource`, which is right for what they
 * test — an `ai` node has to be exercised without a provider — but it meant the
 * only real implementation of that contract was never once executed. It did not
 * work: it handed the whole model to a method that takes a list of strings, and
 * died with a TypeError the first time anybody put an `ai` node into a scenario
 * and typed at it.
 *
 * A fake standing in for the sole implementation of a contract is worth exactly
 * as much as a test that the implementation itself still runs.
 */
it('gathers real topics for the model to answer from', function (): void {
    $found = app(KnowledgeSource::class)->candidates(
        $this->bot->getKey(),
        'how long does delivery take',
        5,
    );

    expect($found)->not->toBeEmpty()
        ->and($found[0])->toHaveKeys(['key', 'title', 'text'])
        ->and($found[0]['text'])->toContain('two to four working days');
});

/**
 * The finder and the model's material agree about what a topic is about.
 *
 * Both read one definition off the model. Written down because the two used to
 * carry their own copies of it, and a drift between them reads as the base
 * answering one way through a scenario and another way through the model, with
 * both screens insisting they read the same knowledge base.
 *
 * The answer is deliberately not part of it: an answer is a paragraph written
 * for a person, and matching on it makes every long topic a better fit for every
 * question than the short topic actually about it.
 */
it('matches a topic on its keywords and variants, never on its answer', function (): void {
    $entry = KnowledgeEntry::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'topic_key' => 'parcels-abroad',
        'question' => 'Do you deliver abroad?',
        'keywords' => ['abroad'],
        'variants' => ['overseas'],
        'answer' => 'Yes. A pangolin courier carries it.',
        'scenario_slug' => null,
    ]);

    expect($entry->searchableTexts())->toContain('Do you deliver abroad?')
        ->and($entry->searchableTexts())->toContain('abroad')
        ->and($entry->searchableTexts())->toContain('overseas')
        ->and($entry->searchableTexts())->not->toContain('Yes. A pangolin courier carries it.');

    $onAnswer = app(KnowledgeSource::class)->candidates($this->bot->getKey(), 'pangolin courier', 5);

    expect(array_column($onAnswer, 'key'))->not->toContain('parcels-abroad');
});

it('costs the same number of queries whether the base holds five topics or forty', function (): void {
    $ask = fn (): mixed => knowledgeFinder()->find($this->bot->getKey(), 'how long does delivery take');

    $small = knowledgeQueries($ask);

    for ($i = 0; $i < 40; $i++) {
        KnowledgeEntry::factory()->create([
            'bot_id' => $this->bot->getKey(),
            'topic_key' => 'filler-'.$i,
            'question' => 'Filler topic number '.$i,
            'keywords' => ['filler'.$i],
            'scenario_slug' => null,
        ]);
    }

    expect(knowledgeQueries($ask))->toBe($small);
});
