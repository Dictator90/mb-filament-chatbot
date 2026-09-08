<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Ai\Redactor;
use MB\FilamentChatbot\Bot\Routing\AiScenarioRouter;
use MB\FilamentChatbot\Bot\Steps\Types\AiStep;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * What is struck out of a visitor's own words before they leave the machine.
 *
 * The package is careful about what it assembles for a model, and none of that
 * care touches the part nobody composes. "Call me on 8 949 415-74-04" is
 * personal data that arrives on its own, and until something removes it, it
 * goes to a provider with every question.
 *
 * The rule cannot be tested by agreement — "we do not put anything extra in"
 * is not a check — but the body of the request can be, and that is what the
 * second half of this file does: it drives a real conversation through the
 * node that answers with a model and reads what the driver was handed.
 */
it('replaces personal data rather than deleting it', function (string $said, string $expected): void {
    expect((new Redactor)->clean($said))->toBe($expected);
})->with([
    'a phone number written the way people write it' => [
        'Call me on 8 949 415-74-04 after six',
        'Call me on [phone] after six',
    ],
    'a landline without a country code' => [
        'Our number is 4956871234, do ring',
        'Our number is [phone], do ring',
    ],
    'an e-mail address' => [
        'Send the contract to ivan.petrov+crm@example.co.uk please',
        'Send the contract to [email] please',
    ],
    /*
     * A ten-digit passport is phone-shaped, and the phone rule reaches it
     * first. The label is wrong and the data is gone, which is the right way
     * round: telling the two apart would mean knowing every country's document
     * format, and the cost of guessing wrong in the other direction is a
     * passport number sitting in somebody else's request log.
     */
    'a passport, under whichever label' => [
        'Do you need passport 4510123456?',
        'Do you need passport [phone]?',
    ],
    'a short reference nothing else claims' => [
        'The receipt says 4510123',
        'The receipt says [number]',
    ],
    'a year survives, because that is what people write about' => [
        'We want to travel in 2026',
        'We want to travel in 2026',
    ],
    'a price survives while it is short enough to be a price' => [
        'Our budget is 90000 for two',
        'Our budget is 90000 for two',
    ],
]);

/**
 * The placeholder is read by the model, so it follows the conversation's own
 * language: an English word dropped into a Russian sentence is one more thing
 * for the model to make sense of before it can answer the question.
 */
it('speaks the placeholder in the language of the conversation', function (): void {
    app()->setLocale('ru');

    expect((new Redactor)->clean('Позвоните на 8 949 415-74-04'))
        ->toBe('Позвоните на [телефон]');
});

/**
 * A host's own reference shape has to be recognised before the general rules
 * see it, or it comes back as "[number]" and neither the model nor the person
 * reading the transcript can tell what was being asked about.
 */
it('lets the host name its own shapes, and reads them first', function (): void {
    config()->set('chatbot.ai.redact.patterns', [
        '/\b[A-Z]-\d{6}-[A-Z0-9]{4}\b/u' => '[booking]',
    ]);

    expect((new Redactor)->clean('My booking is O-260828-V6I9, what is happening with it?'))
        ->toBe('My booking is [booking], what is happening with it?');
});

/**
 * A typo in a config file must not turn every question into an error: this runs
 * on the path that answers a visitor. The built-in rules still apply, so a
 * broken host pattern degrades into "[number]" — unhelpful, never leaky.
 */
it('ignores a pattern that is not one instead of failing the answer', function (): void {
    config()->set('chatbot.ai.redact.patterns', ['not a regex at all' => '[x]']);

    expect((new Redactor)->clean('The receipt says 4510123'))->toBe('The receipt says [number]');
});

/**
 * The seam, not the rules: the node that answers from the knowledge base has to
 * hand the driver the cleaned question. Checked against what the gateway was
 * actually asked, because a redactor nobody called passes every test above.
 */
it('sends the model the cleaned question and not the visitor\'s own words', function (): void {
    Http::preventStrayRequests();

    config()->set('chatbot.ai.default', 'openai');
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');

    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'answer' => 'С девяти до шести по будням.',
                'sources' => ['hours'],
            ])]]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ]),
    ]);

    app()->instance(KnowledgeSource::class, new class implements KnowledgeSource
    {
        public function candidates(?int $botId, ?string $said, int $limit): array
        {
            return [['key' => 'hours', 'title' => 'Часы работы', 'text' => 'С девяти до шести по будням.']];
        }
    });

    app(ChatbotRegistry::class)->registerStep(AiStep::class);

    $bot = Bot::factory()->create(['is_active' => true]);

    $scenario = Scenario::factory()->create(['bot_id' => $bot->getKey(), 'slug' => 'ask', 'is_active' => true]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'model',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'model',
        'type' => 'ai',
    ]);

    $conversation = Conversation::factory()->create(['bot_id' => $bot->getKey()]);

    visitorSays($conversation, 'Часы работы? Мой телефон 8 949 415-74-04');

    Http::assertSent(function (Request $request): bool {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        expect($sent)->toContain('[phone]')
            ->and($sent)->not->toContain('415-74-04');

        return true;
    });
});

/**
 * The other outbound path, and the busier one: routing runs on every message no
 * keyword claimed. Which scenario fits is a question about the shape of the
 * sentence and never about the number in it.
 */
it('cleans the message the scenario router sends as well', function (): void {
    Http::preventStrayRequests();

    config()->set('chatbot.ai.default', 'openai');
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');
    config()->set('chatbot.ai.routing.enabled', true);

    Http::fake([
        'models.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['scenario' => 'booking'])]]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ]),
    ]);

    $scenario = Scenario::factory()->create([
        'bot_id' => null,
        'slug' => 'booking',
        'is_active' => true,
        'invocation' => ['purpose' => 'Talk about an existing booking.'],
    ]);

    app(AiScenarioRouter::class)->route(
        Conversation::factory()->create(),
        'Что с бронью, мой телефон 8 949 415-74-04',
        Scenario::query()->whereKey($scenario->getKey())->get(),
    );

    Http::assertSent(function (Request $request): bool {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        expect($sent)->toContain('[phone]')
            ->and($sent)->not->toContain('415-74-04');

        return true;
    });
});
