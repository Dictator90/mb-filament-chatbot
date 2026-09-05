<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\Drivers\LaravelAiGateway;
use MB\FilamentChatbot\Ai\Drivers\NullGateway;
use MB\FilamentChatbot\Ai\Drivers\OpenAiGateway;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Greeting;
use MB\FilamentChatbot\Bot\Routing\AiScenarioRouter;
use MB\FilamentChatbot\Bot\Routing\ScenarioRouter;
use MB\FilamentChatbot\Bot\ScenarioPicker;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\AiStep;
use MB\FilamentChatbot\Bot\Steps\Types\AiTaskStep;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;

/**
 * What has to hold for a bot that may or may not have a model behind it.
 *
 * Every case here is a way the model layer could quietly take a working bot
 * down or make it lie: a scenario that only walks when a key is configured, an
 * answer composed from material nobody gave the model, a provider outage
 * arriving as an exception in the middle of somebody's turn, a routing decision
 * made by a name the model invented, variables written from an answer that was
 * never understood. None of them shows up as an error anywhere — they show up
 * as a bot that stops talking, or one that confidently says the wrong thing.
 */

/**
 * A knowledge source with fixed material, so the model layer can be tested
 * without the knowledge base it will really be bound to.
 */
final class FixedKnowledge implements KnowledgeSource
{
    /**
     * @param  list<array{title: string, text: string, key: string}>  $entries
     */
    public function __construct(private readonly array $entries = []) {}

    /**
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        return array_slice($this->entries, 0, $limit);
    }
}

/** A router that records whether it was asked at all. */
final class CountingRouter implements ScenarioRouter
{
    public int $calls = 0;

    /**
     * @param  Collection<int, Scenario>  $scenarios
     */
    public function route(Conversation $conversation, ?string $said, Collection $scenarios): ?Scenario
    {
        $this->calls++;

        return $scenarios->first();
    }
}

/**
 * @param  list<array<string, mixed>>  $steps
 */
function aiScenarioOf(array $steps, array $attributes = []): Scenario
{
    $scenario = Scenario::factory()->create($attributes + ['bot_id' => null]);

    foreach ($steps as $index => $step) {
        Step::factory()->create([
            'scenario_id' => $scenario->getKey(),
            'sort' => $index,
        ] + $step);
    }

    return $scenario->load('steps');
}

function aiNodeContext(Step $step, RecordingRuntime $runtime, ?string $said = null): StepContext
{
    return new StepContext(new Conversation, new Scenario, $step, $runtime, $said);
}

/**
 * Points the installation at an OpenAI-compatible endpoint that only exists
 * inside Http::fake(). No test in this file may reach a real provider.
 */
function useFakeProvider(): void
{
    config()->set('chatbot.ai.default', 'openai');
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');
}

/**
 * @param  array<string, mixed>  $content
 */
function fakeAnswer(array $content): void
{
    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($content)]]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ]),
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);

    $registry = app(ChatbotRegistry::class);
    $registry->registerStep(AiStep::class);
    $registry->registerStep(AiTaskStep::class);
});

/**
 * The property the whole layer is arranged around: the package ships with no
 * model, and a scenario drawn with model nodes in it still holds a
 * conversation. Broken, this passes every unit test in the file and takes the
 * bot silent on installations that never configured a provider — which is all
 * of them on the first day.
 */
it('carries the conversation on through both model nodes when no model is configured', function (): void {
    aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'think', 'config' => ['trigger' => 'fallback']],
        ['key' => 'think', 'type' => 'ai', 'next_key' => 'said_it', 'config' => ['else_key' => 'extract']],
        ['key' => 'said_it', 'type' => 'say', 'body' => 'A model answered.'],
        ['key' => 'extract', 'type' => 'ai_task', 'next_key' => 'said_it', 'config' => [
            'else_key' => 'human',
            'instruction' => 'Read the traveller count.',
            'fields' => ['adults'],
            'into' => 'trip',
        ]],
        ['key' => 'human', 'type' => 'say', 'body' => 'Let me fetch a colleague.'],
    ]);

    expect(visitorSays($this->conversation, 'how much is a week in Antalya?'))->toBeTrue()
        ->and(botSaid($this->conversation))->toBe(['Let me fetch a colleague.']);
});

it('declines rather than answering when nothing is configured', function (): void {
    $reply = (new NullGateway)->ask(new AiPrompt('answer', 'hello'));

    expect($reply->ok())->toBeFalse()
        ->and($reply->failure())->toBe(AiFailure::NotConfigured)
        ->and(app(AiGatewayFactory::class)->make()->isAvailable())->toBeFalse();
});

/**
 * An answer the model did not attribute to any of the material it was handed is
 * an answer it made up, and it reads exactly like a real one. This is the check
 * that decides whether a customer can be told an invented refund policy in the
 * company's own voice.
 */
it('does not speak an answer that names none of the material it was given', function (): void {
    useFakeProvider();
    fakeAnswer(['answer' => 'We refund everything, always.', 'sources' => []]);

    app()->instance(KnowledgeSource::class, new FixedKnowledge([
        ['key' => 'refunds', 'title' => 'Refunds', 'text' => 'Refunds are made within ten days.'],
    ]));

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai', 'config' => ['instruction' => 'Answer briefly.']]);

    $result = app(AiStep::class)->run(aiNodeContext($step, $runtime, 'do you refund?'));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($runtime->said)->toBe([]);
});

/**
 * The same check from the other side: a source named that was never offered.
 * A model that answers "from article 42" when it was handed articles 7 and 9
 * has invented both the article and the answer.
 */
it('does not speak an answer that names material nobody gave it', function (): void {
    useFakeProvider();
    fakeAnswer(['answer' => 'Cancellations are free.', 'sources' => ['invented-article']]);

    app()->instance(KnowledgeSource::class, new FixedKnowledge([
        ['key' => 'refunds', 'title' => 'Refunds', 'text' => 'Refunds are made within ten days.'],
    ]));

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai', 'config' => []]);

    $result = app(AiStep::class)->run(aiNodeContext($step, $runtime, 'can I cancel?'));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['claimed'])->toBe(['invented-article'])
        ->and($runtime->said)->toBe([]);
});

it('speaks the answer when the model stays with the material it was handed', function (): void {
    useFakeProvider();
    fakeAnswer(['answer' => 'Refunds take ten days.', 'sources' => ['refunds']]);

    app()->instance(KnowledgeSource::class, new FixedKnowledge([
        ['key' => 'refunds', 'title' => 'Refunds', 'text' => 'Refunds are made within ten days.'],
    ]));

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai', 'config' => []]);

    $result = app(AiStep::class)->run(aiNodeContext($step, $runtime, 'do you refund?'));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($result->spoke)->toBeTrue()
        ->and($runtime->said)->toBe(['Refunds take ten days.'])
        ->and($result->trace['sources'])->toBe(['refunds']);
});

/**
 * A provider having a bad afternoon must arrive as a branch, not as an
 * exception. Thrown, it leaves the visitor's turn ending in nothing and the
 * fault gets reported as "the bot stopped answering".
 */
it('turns a refused status and a dropped connection into declines rather than exceptions', function (): void {
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');

    $gateway = new OpenAiGateway((array) config('chatbot.ai.drivers.openai'));

    Http::fake(['models.example.test/*' => Http::response(['error' => 'nope'], 401)]);

    $refused = $gateway->ask(new AiPrompt('answer', 'hello'));

    Http::fake(['models.example.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $dropped = $gateway->ask(new AiPrompt('answer', 'hello'));

    expect($refused->ok())->toBeFalse()
        ->and($refused->failure())->toBe(AiFailure::Refused)
        ->and($dropped->ok())->toBeFalse()
        ->and($dropped->failure())->toBe(AiFailure::Timeout);
});

it('reads an answer back as prose when no material and no shape were asked for', function (): void {
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');

    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Nine to six.']]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
        ]),
    ]);

    $reply = (new OpenAiGateway((array) config('chatbot.ai.drivers.openai')))
        ->ask(new AiPrompt('answer', 'opening hours?'));

    expect($reply->ok())->toBeTrue()
        ->and($reply->text())->toBe('Nine to six.')
        ->and($reply->usage()->total())->toBe(6);
});

/**
 * Half-understood extraction is worse than none: the conversation carries on
 * against a passenger count nobody said, and every later message is confidently
 * wrong.
 */
it('writes nothing at all when the extraction did not come back readable', function (): void {
    useFakeProvider();

    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'two adults, I think']]],
        ]),
    ]);

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai_task', 'config' => [
        'instruction' => 'Read the traveller count and the city.',
        'fields' => ['adults', 'city'],
        'into' => 'trip',
    ]]);

    $result = app(AiTaskStep::class)->run(aiNodeContext($step, $runtime, 'two of us, from Rostov'));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($runtime->stored)->toBe([]);
});

it('remembers each expected value under its own name once the model returns them', function (): void {
    useFakeProvider();
    fakeAnswer(['adults' => 2, 'city' => 'Rostov']);

    $runtime = new RecordingRuntime;
    $step = Step::factory()->make(['type' => 'ai_task', 'config' => [
        'instruction' => 'Read the traveller count and the city.',
        'fields' => ['adults', 'city'],
        'into' => 'trip',
    ]]);

    $result = app(AiTaskStep::class)->run(aiNodeContext($step, $runtime, 'two of us, from Rostov'));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($runtime->stored['trip_adults'])->toBe('2')
        ->and($runtime->stored['trip_city'])->toBe('Rostov');
});

/**
 * Words route first, and a message they claimed never reaches a model. Broken,
 * every installation that switches routing on pays for a request per message
 * and loses the one property an author can rely on — that what is written on
 * the start node is what routes.
 */
it('never asks a model about a message the keywords already claimed', function (): void {
    $scenario = aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['invocation' => ['purpose' => 'Opening hours']]);

    $router = new CountingRouter;

    $picked = (new ScenarioPicker(app(Greeting::class), $router))
        ->pick($this->conversation, 'what are your hours?', false);

    expect($picked?->getKey())->toBe($scenario->getKey())
        ->and($router->calls)->toBe(0);
});

it('asks the router once the words have found nothing', function (): void {
    aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['invocation' => ['purpose' => 'Opening hours']]);

    $router = new CountingRouter;

    (new ScenarioPicker(app(Greeting::class), $router))
        ->pick($this->conversation, 'is there a pool at the hotel?', false);

    expect($router->calls)->toBe(1);
});

/**
 * A name the model invented must not route anybody. Looked up globally instead
 * of against the list that was sent, a hallucination walks a customer into a
 * scenario nobody made reachable from this channel.
 */
it('ignores a scenario the model named that was not on the list it was given', function (): void {
    useFakeProvider();
    config()->set('chatbot.ai.routing.enabled', true);
    fakeAnswer(['scenario' => 'somewhere-else']);

    $offered = aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['slug' => 'opening-hours', 'invocation' => ['purpose' => 'Opening hours', 'useWhen' => 'They ask when the office is open.']]);

    $chosen = app(AiScenarioRouter::class)->route(
        $this->conversation,
        'is there a pool?',
        Scenario::query()->whereKey($offered->getKey())->get(),
    );

    expect($chosen)->toBeNull();
});

it('takes the scenario the model named when it is one of the offered ones', function (): void {
    useFakeProvider();
    config()->set('chatbot.ai.routing.enabled', true);
    fakeAnswer(['scenario' => 'opening-hours']);

    $offered = aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['slug' => 'opening-hours', 'invocation' => ['purpose' => 'Opening hours']]);

    $chosen = app(AiScenarioRouter::class)->route(
        $this->conversation,
        'when do you open?',
        Scenario::query()->whereKey($offered->getKey())->get(),
    );

    expect($chosen?->getKey())->toBe($offered->getKey());
});

/**
 * A scenario whose author never wrote an invocation contract is not a candidate
 * for a model at all, and with routing switched off nothing is asked in the
 * first place. Both are checked here because both end in the same silence, and
 * neither of them may cost a request.
 */
it('asks nothing when routing is off or no scenario offers itself to a model', function (): void {
    useFakeProvider();

    /*
     * The assertion that matters is the one at the end: not a request was made
     * and came back empty, but that none was made at all. A cost check written
     * against the return value alone passes on a class that asks the provider
     * every time and ignores the answer.
     */
    Http::fake();

    $withoutContract = aiScenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'say_it', 'config' => ['trigger' => 'keyword', 'keywords' => ['hours']]],
        ['key' => 'say_it', 'type' => 'say', 'body' => 'Nine to six.'],
    ], ['slug' => 'no-contract']);

    config()->set('chatbot.ai.routing.enabled', true);

    $withoutContractChosen = app(AiScenarioRouter::class)->route(
        $this->conversation,
        'is there a pool?',
        Scenario::query()->whereKey($withoutContract->getKey())->get(),
    );

    config()->set('chatbot.ai.routing.enabled', false);

    $withRoutingOff = app(AiScenarioRouter::class)->route(
        $this->conversation,
        'is there a pool?',
        Scenario::query()->get(),
    );

    Http::assertNothingSent();

    expect($withoutContractChosen)->toBeNull()
        ->and($withRoutingOff)->toBeNull();
});

/**
 * The driver whose library the package deliberately does not require. Reporting
 * itself unavailable is the whole contract; throwing from the factory instead
 * would make one line of configuration fatal on a customer's first message.
 */
it('reports the laravel-ai driver unavailable when the library is not installed, without breaking the factory', function (): void {
    config()->set('chatbot.ai.default', 'laravel-ai');

    $gateway = app(AiGatewayFactory::class)->make();

    expect($gateway)->toBeInstanceOf(LaravelAiGateway::class)
        ->and($gateway->isAvailable())->toBeFalse()
        ->and($gateway->ask(new AiPrompt('answer', 'hello'))->ok())->toBeFalse();
});

it('falls back to the null gateway for a driver name nobody registered', function (): void {
    config()->set('chatbot.ai.default', 'a-typo');

    expect(app(AiGatewayFactory::class)->make())->toBeInstanceOf(NullGateway::class);
});

it('builds a host driver registered into the factory', function (): void {
    $factory = app(AiGatewayFactory::class);
    $factory->extend('house', fn (array $config): NullGateway => new NullGateway);

    config()->set('chatbot.ai.default', 'house');

    expect($factory->make())->toBeInstanceOf(NullGateway::class)
        ->and($factory->names())->toHaveKey('house');
});
