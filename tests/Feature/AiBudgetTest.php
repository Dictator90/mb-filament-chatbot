<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Routing\AiScenarioRouter;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\AiStep;
use MB\FilamentChatbot\Bot\Steps\Types\AiTaskStep;
use MB\FilamentChatbot\Budgets\BudgetGuard;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\BudgetPeriod;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Quality\QualityRunner;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * What has to hold once a budget exists: that it is actually in the way.
 *
 * A limit somebody types into a form and nothing ever reads is worse than no
 * limit at all — it is a setting that looks like a defence, so nobody looks for
 * the defence again. Every case here is one of the four ways that happens: the
 * guard never called, called after the money is already spent, called and then
 * left holding an estimate for a call that failed, or settled with a number the
 * package guessed instead of the one the provider counted.
 *
 * The other half is what an exhausted budget must feel like from the outside:
 * the same thing as an unset key. A node takes its "could not" exit and the
 * router falls back to words, because a bot that stops mid-sentence on the day
 * accounting closes is worse than one that answers a little less well.
 */

/**
 * Material for the answering node, so that a test about money is not a test
 * about the knowledge base having found something.
 *
 * Named apart from the engine suite's equivalent: Pest loads every test file
 * into one process, and two files declaring one class is a fatal nobody can read
 * out of the output.
 */
final class BudgetKnowledge implements KnowledgeSource
{
    /**
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        return [['key' => 'refunds', 'title' => 'Refunds', 'text' => 'Refunds are made within ten days.']];
    }
}

/**
 * A driver that answers and counts nothing back, which is the shape of every
 * provider and library that does not report usage.
 */
final class QuietGateway implements AiGateway
{
    public function name(): string
    {
        return 'quiet';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function ask(AiPrompt $prompt): AiReply
    {
        return AiReply::structured(['answer' => 'Ten days.'], 'Ten days.', ['refunds']);
    }
}

/**
 * Points the installation at an endpoint that exists only inside Http::fake(),
 * priced so that the cost column has something to be right or wrong about.
 */
function useBudgetedProvider(): void
{
    config()->set('chatbot.ai.default', 'openai');
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');
    config()->set('chatbot.ai.drivers.openai.price_per_million_input', 2);
    config()->set('chatbot.ai.drivers.openai.price_per_million_output', 6);
    config()->set('chatbot.budgets.enabled', true);
    config()->set('chatbot.budgets.monthly_tokens', null);
    config()->set('chatbot.budgets.monthly_cost', null);
}

/**
 * @param  array<string, mixed>  $content
 */
function budgetedAnswer(array $content, int $inputTokens = 11, int $outputTokens = 7): void
{
    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($content)]]],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ]),
    ]);
}

function budgetedNodeContext(Conversation $conversation, RecordingRuntime $runtime, string $said): StepContext
{
    return new StepContext(
        $conversation,
        new Scenario,
        Step::factory()->make(['type' => 'ai', 'config' => ['instruction' => 'Answer briefly.']]),
        $runtime,
        $said,
    );
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @param  array<string, mixed>  $attributes
 */
function budgetedScenario(array $steps, array $attributes = []): Scenario
{
    $scenario = Scenario::factory()->create($attributes + ['bot_id' => null]);

    foreach ($steps as $index => $step) {
        Step::factory()->create(['scenario_id' => $scenario->getKey(), 'sort' => $index] + $step);
    }

    return $scenario->load('steps');
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->bot = Bot::factory()->create();
    $this->conversation = Conversation::factory()->create([
        'bot_id' => $this->bot->getKey(),
        'channel' => 'site',
    ]);

    app()->instance(KnowledgeSource::class, new BudgetKnowledge);
});

/**
 * The reservation is the point of the whole guard, and it is only a guard if it
 * is already written when the request goes out. Settled afterwards instead, two
 * conversations arriving in the same second both read a balance neither of them
 * has spent yet, and both spend it — the month ends over budget with a ledger
 * saying it never was.
 */
it('has already charged the month by the time the request reaches the provider', function (): void {
    useBudgetedProvider();

    $chargedWhenAsked = null;

    Http::fake(['models.example.test/*' => function () use (&$chargedWhenAsked) {
        $chargedWhenAsked = (int) BudgetPeriod::query()->sum('input_tokens');

        return Http::response([
            'choices' => [['message' => ['content' => json_encode(['answer' => 'Ten days.', 'sources' => ['refunds']])]]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ]);
    }]);

    $result = app(AiStep::class)->run(budgetedNodeContext($this->conversation, new RecordingRuntime, 'do you refund?'));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($chargedWhenAsked)->toBeGreaterThan(0);
});

/**
 * An estimate is a guess and settle() is the correction. Left as it was, a
 * month's ledger would be a record of how the package guesses rather than of
 * what the provider charged, and the two diverge in exactly the direction
 * nobody notices — downwards, on the long answers.
 */
it('writes back what the provider counted rather than the estimate it reserved', function (): void {
    useBudgetedProvider();
    budgetedAnswer(['answer' => 'Ten days.', 'sources' => ['refunds']]);

    app(AiStep::class)->run(budgetedNodeContext($this->conversation, new RecordingRuntime, 'do you refund?'));

    $period = app(BudgetGuard::class)->usage($this->bot);

    expect($period->input_tokens)->toBe(11)
        ->and($period->output_tokens)->toBe(7)
        ->and($period->cost_micro)->toBe(11 * 2 + 7 * 6)
        ->and($period->calls)->toBe(1);
});

/**
 * A run of provider errors must not eat the month. The estimate was written
 * before the request left; if a failure leaves it standing, a bad afternoon at
 * the provider spends a budget on answers nobody ever received, and the panel
 * shows a month spent on nothing.
 */
it('gives the estimate back when the request failed', function (): void {
    useBudgetedProvider();

    Http::fake(['models.example.test/*' => Http::response(['error' => 'down'], 500)]);

    $result = app(AiStep::class)->run(budgetedNodeContext($this->conversation, new RecordingRuntime, 'do you refund?'));

    $period = app(BudgetGuard::class)->usage($this->bot);

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($period->totalTokens())->toBe(0)
        ->and($period->calls)->toBe(0);
});

/**
 * Running out of money is the cheapest, most predictable failure there is, and
 * it must take the same branch as the unpredictable ones. Thrown instead, it
 * ends the visitor's turn in silence on the busiest day of the month — and the
 * fault gets reported as "the bot stopped answering", which is not where anybody
 * would look for a budget.
 */
it('takes the node down its second exit when the month is spent, without asking the provider', function (): void {
    useBudgetedProvider();
    budgetedAnswer(['answer' => 'Ten days.', 'sources' => ['refunds']]);

    $this->bot->update(['monthly_token_budget' => 5]);

    $runtime = new RecordingRuntime;
    $result = app(AiStep::class)->run(budgetedNodeContext($this->conversation, $runtime, 'do you refund?'));

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['failure'])->toBe(AiFailure::BudgetExhausted->value)
        ->and($runtime->said)->toBe([]);
});

/**
 * The extraction node spends the same money as the answering one, and its own
 * "could not" exit is the one that must be taken when there is none left.
 * Missed while wiring the two nodes, this is the half that goes on calling a
 * provider after the other half has stopped — and it is the half nobody watches,
 * because it never says anything to anybody.
 */
it('takes the extraction node down its second exit when the month is spent', function (): void {
    useBudgetedProvider();
    budgetedAnswer(['adults' => 2, 'city' => 'Rostov']);

    $this->bot->update(['monthly_token_budget' => 5]);

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai_task', 'config' => [
        'instruction' => 'Read the traveller count and the city.',
        'fields' => ['adults', 'city'],
        'into' => 'trip',
    ]]);

    $result = app(AiTaskStep::class)->run(
        new StepContext($this->conversation, new Scenario, $step, $runtime, 'two of us, from Rostov'),
    );

    Http::assertNothingSent();

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['failure'])->toBe(AiFailure::BudgetExhausted->value)
        ->and($runtime->stored)->toBe([]);
});

/**
 * Not every provider counts tokens back, and a call that happened still cost
 * something. Settled at zero, the driver nobody can measure would be the
 * cheapest line in the ledger and a budget set against it would never be
 * reached — the one arrangement where a limit is on, spending is real and the
 * month never ends.
 */
it('keeps the estimate when the provider reports no usage at all', function (): void {
    config()->set('chatbot.budgets.enabled', true);
    config()->set('chatbot.ai.default', 'quiet');

    app(AiGatewayFactory::class)->extend('quiet', fn (array $config): QuietGateway => new QuietGateway);

    app(AiStep::class)->run(budgetedNodeContext($this->conversation, new RecordingRuntime, 'do you refund?'));

    $period = app(BudgetGuard::class)->usage($this->bot);

    expect($period->totalTokens())->toBeGreaterThan(0)
        ->and($period->calls)->toBe(1);
});

/**
 * The router is asked on every message the words did not claim, so an exhausted
 * budget arrives there first and stays for the rest of the month. It has to be
 * silence rather than an error: the picker carries on to the fallback exactly as
 * it would on an installation with no model at all, and the visitor is answered.
 */
it('routes by words and keeps answering once the model budget is spent', function (): void {
    useBudgetedProvider();
    budgetedAnswer(['scenario' => 'opening-hours']);
    config()->set('chatbot.ai.routing.enabled', true);

    $this->bot->update(['monthly_token_budget' => 5]);

    budgetedScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['slug' => 'opening-hours', 'invocation' => ['purpose' => 'Opening hours']]);

    budgetedScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'sorry', 'config' => ['trigger' => 'fallback']],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch a colleague.'],
    ], ['slug' => 'fallback']);

    $chosen = app(AiScenarioRouter::class)->route(
        $this->conversation,
        'is there a pool?',
        Scenario::query()->get(),
    );

    expect($chosen)->toBeNull();

    expect(visitorSays($this->conversation, 'is there a pool?'))->toBeTrue()
        ->and(botSaid($this->conversation))->toBe(['Let me fetch a colleague.']);

    Http::assertNothingSent();
});

/**
 * A rehearsal drives the real engine, which is what makes it worth trusting and
 * what makes it dangerous: unattended, nightly and repeated, it would spend the
 * budget the customers talk through and grade the bot on wording that changes
 * between two Tuesdays. So it rehearses the path the package promises — the
 * scenario with no model behind it — and the ledger stays untouched.
 */
it('rehearses a quality check without reaching a provider or spending a budget', function (): void {
    useBudgetedProvider();
    budgetedAnswer(['answer' => 'Ten days.', 'sources' => ['refunds']]);

    $scenario = budgetedScenario([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'think', 'config' => ['trigger' => 'fallback']],
        ['key' => 'think', 'type' => 'ai', 'next_key' => 'answered', 'config' => ['else_key' => 'sorry']],
        ['key' => 'answered', 'type' => 'say', 'body' => 'A model answered.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch a colleague.'],
    ]);

    $check = QualityTest::factory()
        ->forScenario($scenario->getKey(), $scenario->slug)
        ->saying(['do you refund?'], ['says' => 'Let me fetch a colleague.'])
        ->create(['bot_id' => $this->bot->getKey()]);

    $result = app(QualityRunner::class)->run($check);

    Http::assertNothingSent();

    /*
     * Counted by rows rather than by BudgetGuard::usage(), which opens the
     * period it is asked about and would leave the very row this is looking for.
     */
    expect($result->isPassing())->toBeTrue()
        ->and(BudgetPeriod::query()->count())->toBe(0);
});
