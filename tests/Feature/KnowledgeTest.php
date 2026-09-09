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
use MB\FilamentChatbot\Knowledge\KnowledgeVectors;
use MB\FilamentChatbot\Knowledge\KnowledgeVerdict;
use MB\FilamentChatbot\Knowledge\RootExtractor;
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

/**
 * The second pass: what a question looks like, once matching its words has
 * found nothing.
 *
 * A typo is not an unusual question, it is the ordinary one. "waranty" shares
 * no root with anything in the base — the root search is silent, the bot says
 * it does not know and fetches a person — while the letters say plainly which
 * topic was meant.
 */
it('recognises a question the root search could not, by its shape', function (): void {
    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'how long is the waranty on hardwear?');

    expect($verdict)->not->toBeNull()
        ->and($verdict->entry()->topic_key)->toBe('warranty')
        ->and($verdict->via())->toBe(KnowledgeVerdict::BY_SHAPE)
        ->and($verdict->roots())->toBe([]);
});

/**
 * A misspelling in every word of the question at once, which is what a message
 * typed on a phone looks like. Root matching needs one word spelled right.
 */
it('recognises a question misspelled throughout', function (): void {
    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'do you shipp internationaly?');

    expect($verdict)->not->toBeNull()
        ->and($verdict->entry()->topic_key)->toBe('international-shipping');
});

/**
 * Two topics genuinely indistinguishable — close on words and equally close on
 * shape, because they are written to be. This is the case the honest "I do not
 * know" exists for, and shape must not talk it into an answer.
 */
it('stays silent when shape is as undecided as the words were', function (): void {
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
 * Distant topics are dropped rather than returned as the best of a bad set.
 * This pass runs where the bot would otherwise say honestly that it does not
 * know, and answering the wrong topic there is worse than answering nothing.
 */
it('still finds nothing for a question the base has no topic for', function (): void {
    expect(knowledgeFinder()->find($this->bot->getKey(), 'do you sell bicycles for toddlers?'))
        ->toBeNull();
});

/**
 * A host that would rather have the honest silence can have it, and the first
 * pass is untouched by the switch.
 */
it('can be switched off without taking the word search with it', function (): void {
    config()->set('chatbot.knowledge.similarity', false);

    expect(knowledgeFinder()->find($this->bot->getKey(), 'how long is the waranty on hardwear?'))->toBeNull()
        ->and(knowledgeFinder()->find($this->bot->getKey(), 'where is my refund for this order')?->entry()->topic_key)
        ->toBe('refund-status');
});

/**
 * Roots are compared truncated, and truncation invents ties: "shipp" is the
 * root of both the shipping topic and the international one, so a question
 * about shipping abroad matches them equally and the bot goes quiet. Spelled
 * out, the two are not close at all.
 *
 * The tie is settled and not overturned: the topic that wins is one of the two
 * the words tied, and it wins by a margin the words never had.
 */
it('lets shape settle a tie the truncated roots invented', function (): void {
    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'do you shipp internationaly?');

    expect($verdict)->not->toBeNull()
        ->and($verdict->entry()->topic_key)->toBe('international-shipping')
        ->and($verdict->via())->toBe(KnowledgeVerdict::BY_SHAPE);
});

/**
 * Shape may break a tie and never overturn one. A topic the word search did not
 * put on the table cannot arrive from the second pass: that would be the
 * stronger match overruled by the weaker one, on a question the words had
 * already understood.
 */
it('never answers with a topic the words did not put on the table', function (): void {
    foreach (['battery-refill', 'battery-replace'] as $key) {
        KnowledgeEntry::factory()->create([
            'bot_id' => $this->bot->getKey(),
            'topic_key' => $key,
            'question' => 'What about the battery pack?',
            'keywords' => ['battery pack'],
            'scenario_slug' => null,
        ]);
    }

    KnowledgeEntry::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'topic_key' => 'batery-shaped-topic',
        'question' => 'battery pack',
        'keywords' => [],
        'scenario_slug' => null,
    ]);

    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'battery pack');

    expect($verdict?->entry()->topic_key)->not->toBe('batery-shaped-topic');
});

/**
 * A host that knows its own language may replace the stemming, and one binding
 * has to reach both readers.
 *
 * The default cut is deliberately blunt and stays the default — the package
 * cannot claim to speak the languages it is installed into. But truncation is
 * only "equally crude everywhere" while both sides of a comparison are
 * truncated, and an application whose keyword lists were milled by a real
 * stemmer stores roots this class never produces. Search then cuts the question
 * by one rule and the topic by another: nothing fails, matching simply stops
 * finding things, and the bot reads as one that does not know half of its own
 * base.
 *
 * The assertion is about both sides on purpose. Binding a stemmer that reached
 * the lookup and not the index would be worse than no seam at all — the two
 * would disagree by construction, on every topic, for ever.
 */
it('lets the host replace the stemming, on the question and on the topic alike', function (): void {
    /*
     * An irregular verb, because a regular one would prove nothing here: the
     * default cut folds "kayaks" and "kayaking" onto "kayak" perfectly well by
     * accident, and a test built on such a pair passes with the seam bypassed.
     * "swam" and "swimming" truncate to "swam" and "swimm" — no match at all —
     * and only a stemmer that knows the language brings them together.
     *
     * The subject is one the demo base has never heard of on purpose. A topic
     * tying with a demo topic would be refused an answer by the rule two tests
     * above, and this test would then be measuring that rule instead of this
     * seam.
     */
    app()->bind(RootExtractor::class, fn (): RootExtractor => new class extends RootExtractor
    {
        protected function stem(string $word): string
        {
            return in_array($word, ['swim', 'swims', 'swimming', 'swam', 'swum'], true)
                ? 'swim'
                : parent::stem($word);
        }
    });

    KnowledgeEntry::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'topic_key' => 'pool-hours',
        'question' => 'When may guests swim?',
        'keywords' => ['swam'],
        'answer' => 'The pool is open from seven.',
        'scenario_slug' => null,
    ]);

    $verdict = knowledgeFinder()->find($this->bot->getKey(), 'swimming');

    expect($verdict?->entry()->topic_key)->toBe('pool-hours');
});

/**
 * A phrasing recorded as "how customers ask this" is measured against on its own.
 *
 * That is the entire purpose of the field, and it did not work. A topic was one
 * vector glued out of everything written about it — its question, a dozen
 * keywords, every phrasing — so closeness measured how much had been written
 * rather than how near the question was: the more carefully a topic was filled
 * in, the further every question sat from it. A phrasing recorded character for
 * character scored under a third and never cleared the threshold, which on a
 * real base meant the bot not knowing the questions somebody had sat down and
 * listed for it.
 *
 * Asserted on the shape pass directly rather than through the finder, because
 * the finder only reaches it when matching words has gone quiet — which needs a
 * base of a few hundred topics to happen naturally, and would make this a test
 * about the size of the demo data.
 *
 * The thorough topic is the point: it is the one the old code punished.
 */
it('scores a recorded phrasing on its own merits, not against everything else written', function (): void {
    $thorough = KnowledgeEntry::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'topic_key' => 'pool-opening',
        'question' => 'Pool opening times',
        'keywords' => [
            'pool', 'water', 'towel', 'lifeguard', 'lane', 'sauna',
            'jacuzzi', 'changing', 'locker', 'depth', 'children',
        ],
        'variants' => [
            'Until what time is the pool open?',
            'Is the pool heated?',
            'Can children use the pool?',
        ],
        'answer' => 'The pool is open from seven.',
        'scenario_slug' => null,
    ]);

    $nearest = app(KnowledgeVectors::class)->nearest(
        'Until what time is the pool open?',
        KnowledgeEntry::query()->where('bot_id', $this->bot->getKey())->get(),
        3,
    );

    expect($nearest)->not->toBeEmpty()
        ->and($nearest[0]['entry']->topic_key)->toBe($thorough->topic_key)
        ->and($nearest[0]['score'])->toBeGreaterThan(0.9);
});
