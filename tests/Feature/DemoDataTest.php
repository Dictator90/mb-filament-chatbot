<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Bot\ScenarioPicker;
use MB\FilamentChatbot\Demo\DemoData;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\QuickReply;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * The demo is the first thing a new installation runs and the only proof it has
 * that the package works at all. Everything asserted below fails silently in
 * production of that promise: a transition pointing at a node that was renamed
 * does not throw, it simply ends the conversation, and the person who has just
 * installed the package reads that as "the bot does not answer".
 */
beforeEach(function (): void {
    $this->bot = app(DemoData::class)->seed();

    $this->conversation = Conversation::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'channel' => 'site',
    ]);
});

/**
 * @return Collection<int, Scenario>
 */
function demoScenarios(int $botId)
{
    return Scenario::query()
        ->where('bot_id', $botId)
        ->with('steps')
        ->get();
}

/**
 * A broken transition is the one demo fault nobody sees coming: the editor draws
 * the arrow from the key it was given, and a key that resolves to nothing ends
 * the walk without a word. So every exit of every node is followed here — the
 * plain one, the branch ones and the second one hidden in the settings.
 */
it('points every transition at a step that exists in the same scenario', function (): void {
    $broken = [];

    foreach (demoScenarios($this->bot->getKey()) as $scenario) {
        $keys = $scenario->steps->pluck('key')->all();

        foreach ($scenario->steps as $step) {
            $targets = [];

            if (trim((string) $step->next_key) !== '') {
                $targets[] = $step->next_key;
            }

            foreach ($step->choices() as $choice) {
                $targets[] = $choice['next'];
            }

            if ($step->secondTarget() !== null) {
                $targets[] = $step->secondTarget();
            }

            foreach ($targets as $target) {
                if (! in_array($target, $keys, true)) {
                    $broken[] = "{$scenario->slug}.{$step->key} -> {$target}";
                }
            }
        }
    }

    expect($broken)->toBe([]);
});

/**
 * A jump or a call naming a scenario that is not there is worse than a broken
 * key: the engine hands the conversation to a person, so the visitor gets an
 * answer and nobody learns that the demo is wrong.
 */
it('sends every jump and call to a scenario that exists', function (): void {
    $scenarios = demoScenarios($this->bot->getKey());
    $slugs = $scenarios->pluck('slug')->all();
    $missing = [];

    foreach ($scenarios as $scenario) {
        foreach ($scenario->steps as $step) {
            if (! in_array($step->type, ['jump', 'call'], true)) {
                continue;
            }

            $target = (string) $step->setting('scenario', '');

            if (! in_array($target, $slugs, true)) {
                $missing[] = "{$scenario->slug}.{$step->key} -> {$target}";
            }
        }
    }

    foreach (KnowledgeEntry::query()->leadingToScenario()->get() as $entry) {
        if (! in_array((string) $entry->scenario_slug, $slugs, true)) {
            $missing[] = "knowledge:{$entry->topic_key} -> {$entry->scenario_slug}";
        }
    }

    expect($missing)->toBe([]);
});

it('gives every scenario exactly one start node', function (): void {
    foreach (demoScenarios($this->bot->getKey()) as $scenario) {
        $starts = $scenario->steps->where('type', Step::TYPE_START);

        expect($starts)->toHaveCount(1, "scenario [{$scenario->slug}]");
    }
});

/**
 * Anyone experimenting runs the seeder twice — once to look, once after breaking
 * something. A second copy of the fallback would race the first for every
 * message the bot receives, and which one answers would depend on nothing the
 * author can see.
 *
 * Quick replies are counted alongside the rest because they are the only part of
 * the demo with no unique index behind it. Scenarios, steps and topics would at
 * least fail loudly on a second insert; a duplicated quick reply just appears
 * twice in the operator's picker forever.
 */
it('writes the same rows when it is run again', function (): void {
    $scenarios = Scenario::query()->count();
    $steps = Step::query()->count();
    $topics = KnowledgeEntry::query()->count();
    $replies = QuickReply::query()->count();

    app(DemoData::class)->seed();

    expect(Scenario::query()->count())->toBe($scenarios)
        ->and(Step::query()->count())->toBe($steps)
        ->and(KnowledgeEntry::query()->count())->toBe($topics)
        ->and(QuickReply::query()->count())->toBe($replies);
});

/**
 * The shared scenario must stay unreachable from outside.
 *
 * Routed to directly it would open with "How should I address you?" at somebody
 * who asked about a refund — an interrogation with no stated reason, which reads
 * as a bot that has lost the thread.
 */
it('never routes a visitor into the scenario that only exists to be called', function (string $said, bool $isFirst): void {
    $picked = app(ScenarioPicker::class)->pick($this->conversation, $said, $isFirst);

    expect($picked?->slug)->not->toBe('identify');
})->with([
    'a greeting' => ['hello', true],
    'a question' => ['what is your name', false],
    'nonsense' => ['asdfgh', false],
    'the scenario name itself' => ['identify', false],
]);

/**
 * Walks the lookup end to end. The value the visitor typed has to come back in
 * the answer: a substitution that silently renders empty turns a helpful reply
 * into "Order  left the warehouse", which is the kind of thing that is only ever
 * noticed by a customer.
 */
it('walks the order lookup from a keyword to a closed conversation', function (): void {
    visitorSays($this->conversation, 'where is my order');

    expect(botSaid($this->conversation))->toBe(['Happy to check. What is the order number?']);

    visitorSays($this->conversation, 'ABC-123');

    expect(botSaid($this->conversation))->toBe([
        'Happy to check. What is the order number?',
        'Order ABC-123 left the warehouse and is due to arrive on Friday.',
        'Anything else I can help with?',
    ])->and($this->conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

/**
 * Walks the support request, which is the only demo scenario that leaves its own
 * graph and comes back. The name in the closing line arrives through the called
 * scenario's result, so a return that lost its value would show up here as a
 * greeting addressed to nobody.
 */
it('walks the support request through the scenario it calls and back', function (): void {
    visitorSays($this->conversation, 'my printer is broken');
    visitorSays($this->conversation, 'Alex');
    visitorSays($this->conversation, 'alex@example.test');
    visitorSays($this->conversation, 'it will not switch on any more');
    visitorSays($this->conversation, 'today');

    $said = botSaid($this->conversation);

    expect($said)->toContain('Thanks Alex — I have written all of that down.')
        ->and($said)->toContain('Passing you to the support team now.')
        ->and($this->conversation->refresh()->bot_state)->toBe(BotState::HandedOff)
        ->and($this->conversation->bot_answers['visitor_name'] ?? null)->toBe('Alex');
});

/**
 * A knowledge base of nothing but answers teaches the wrong lesson. The topic
 * that refuses to answer is the one worth shipping: a refund date invented by a
 * bot is quoted back at the company as a promise.
 */
it('ships a topic the bot is forbidden to answer', function (): void {
    $handoff = KnowledgeEntry::query()
        ->where('bot_id', $this->bot->getKey())
        ->where('mode', KnowledgeMode::Handoff->value)
        ->get();

    expect($handoff)->not->toBeEmpty()
        ->and($handoff->first()->answer)->toBeNull();
});
