<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use MB\FilamentChatbot\Ai\AiConversationGateway;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\AiTool;
use MB\FilamentChatbot\Ai\AiToolCall;
use MB\FilamentChatbot\Ai\AiTurn;
use MB\FilamentChatbot\Ai\AiUsage;
use MB\FilamentChatbot\Ai\Drivers\Concerns\TalksToModels;
use stdClass;
use Throwable;

/**
 * Anthropic's Messages API, spoken directly.
 *
 * Not through the OpenAI-compatible endpoint, although one exists. Three things
 * differ where it matters: the instruction is a `system` field of its own
 * rather than a message with a role, the answer arrives as a list of content
 * blocks rather than a choice, and the token counts come back under different
 * names. Routing all of that through a compatibility layer means debugging two
 * translations whenever an answer is not what it should be — and the layer is
 * somebody's second priority, while this request is four fields.
 *
 * **No `response_format`.** The API has none, so JSON is asked for in words and
 * read back with the same fence-stripping decoder every driver here uses: a
 * model wraps JSON in a code block whatever the instruction says.
 *
 * Nothing throws out of this class, like every other gateway: the caller is a
 * node whose author drew a second exit for exactly this, and an exception
 * instead would end the visitor's turn in silence.
 */
final class AnthropicGateway implements AiConversationGateway
{
    use TalksToModels;

    /**
     * The version of the API this driver was written against.
     *
     * Sent on every request because Anthropic requires it and uses it to keep
     * old integrations working: pinned here rather than configurable, since a
     * version this code has never seen would change the shapes it reads.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '';
    }

    /**
     * @throws ConnectionException
     */
    protected function send(AiPrompt $prompt): Response
    {
        return $this->post($this->payload($prompt));
    }

    /**
     * Carries on an exchange, with the tools the caller is willing to run.
     *
     * The same endpoint as `ask()` and a different body, which is the whole of
     * the difference: history goes up as `messages`, tool results as
     * `tool_result` blocks inside a user turn, and what comes back is read for
     * `tool_use` blocks before it is read for text.
     *
     * Failures are handled here rather than in {@see TalksToModels} because
     * that trait's `ask()` is written around an `AiPrompt` — asking it to serve
     * both would mean a prompt argument this path has nothing to put in it.
     */
    public function converse(AiTurn $turn): AiReply
    {
        if (! $this->isAvailable()) {
            return AiReply::declined(AiFailure::NotConfigured);
        }

        try {
            $response = $this->post($this->exchange($turn));
        } catch (ConnectionException $exception) {
            return AiReply::declined($this->connectionFailure($exception), $exception->getMessage());
        } catch (Throwable $exception) {
            return AiReply::declined(AiFailure::Unavailable, $exception::class.': '.$exception->getMessage());
        }

        if (! $response->successful()) {
            return AiReply::declined(
                $response->status() >= 500 ? AiFailure::Unavailable : AiFailure::Refused,
                $this->complaint($response),
            );
        }

        return $this->readExchange($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    private function post(array $payload): Response
    {
        return $this->request()
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => self::API_VERSION,
            ])
            ->post($this->baseUrl().'/messages', $payload);
    }

    /**
     * The request body for an exchange.
     *
     * Consecutive tool results are merged into one user turn, because that is
     * how the API wants them: a model that asked for three things at once gets
     * one user message holding three `tool_result` blocks, and sending three
     * separate messages instead is refused with a complaint about roles.
     *
     * @return array<string, mixed>
     */
    private function exchange(AiTurn $turn): array
    {
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $turn->maxTokens > 0 ? $turn->maxTokens : $this->maxTokens(),
            'system' => $turn->instruction,
            'messages' => $this->messagesOf($turn),
        ];

        if ($turn->hasTools()) {
            $payload['tools'] = array_map(static fn (AiTool $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->schema === [] ? ['type' => 'object'] : $tool->schema,
            ], $turn->tools);
        }

        if ($turn->temperature !== null) {
            $payload['temperature'] = $turn->temperature;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesOf(AiTurn $turn): array
    {
        $messages = [];

        foreach ($turn->messages as $message) {
            if ($message->isToolResult()) {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $message->callId,
                    'content' => $message->text,
                ];

                if (! $message->ok) {
                    $block['is_error'] = true;
                }

                $last = count($messages) - 1;

                if ($last >= 0 && ($messages[$last]['role'] ?? '') === 'user' && is_array($messages[$last]['content'] ?? null)) {
                    $messages[$last]['content'][] = $block;

                    continue;
                }

                $messages[] = ['role' => 'user', 'content' => [$block]];

                continue;
            }

            if ($message->hasCalls()) {
                $content = [];

                if ($message->text !== '') {
                    $content[] = ['type' => 'text', 'text' => $message->text];
                }

                foreach ($message->calls as $call) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $call->id,
                        'name' => $call->name,
                        'input' => $call->input === [] ? new stdClass : $call->input,
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }

            if ($message->text === '') {
                continue;
            }

            $messages[] = ['role' => $message->role, 'content' => $message->text];
        }

        return $messages;
    }

    /**
     * Tool requests first, text second.
     *
     * A model that asks for something usually says a sentence alongside it, and
     * reading the text first would end the round there — the tool never runs,
     * and the visitor is told the bot is "looking into it" by a bot that has
     * stopped.
     */
    private function readExchange(Response $response): AiReply
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $usage = $this->usageOf($body);
        $calls = [];

        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                $calls[] = new AiToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    input: is_array($block['input'] ?? null) ? $block['input'] : [],
                );
            }
        }

        $text = $this->textOf($body);

        if ($calls !== []) {
            return AiReply::calling($calls, $text, $usage);
        }

        return $text === ''
            ? AiReply::declined(AiFailure::Unparseable, 'The provider answered with an empty message.', $usage)
            : AiReply::answered($text, [], $usage);
    }

    /**
     * The request body.
     *
     * `system` is a top-level field here, and that is the whole reason this
     * driver exists rather than a config pointing at a compatibility endpoint:
     * the instruction, the material the answer must come from and the shape of
     * the reply all travel in it, and a provider that treats them as an
     * ordinary first message weighs them differently.
     *
     * @return array<string, mixed>
     */
    private function payload(AiPrompt $prompt): array
    {
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $prompt->maxTokens > 0 ? $prompt->maxTokens : $this->maxTokens(),
            'system' => $this->systemMessage($prompt),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->question],
            ],
        ];

        $temperature = $this->chosenTemperature($prompt);

        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        return $payload;
    }

    /**
     * Температура уходит, только если её назвали.
     *
     * Новые модели Anthropic отвечают на неё отказом целиком:
     * «`temperature` is deprecated for this model», статус 400, и разговор
     * уходит во второй выход узла — то есть бот молчит из-за настройки,
     * которую никто не менял. Умолчания у драйвера поэтому нет: прислали
     * число в запросе или вписали в настройки — отправляем, иначе поле
     * не упоминаем вовсе.
     */
    private function chosenTemperature(AiPrompt $prompt): ?float
    {
        if ($prompt->temperature !== null) {
            return $prompt->temperature;
        }

        $configured = $this->config['temperature'] ?? null;

        return $configured === null ? null : (float) $configured;
    }

    /**
     * The provider's own words about why it said no, with the status behind them.
     *
     * Anthropic answers `{"type": "error", "error": {"type": ..., "message": ...}}`,
     * and the message is what names the fix: an unknown model, a key that is
     * not for this account, credit that ran out. A bare status sends whoever is
     * debugging to a search engine instead.
     */
    protected function complaint(Response $response): string
    {
        $said = trim((string) data_get($response->json(), 'error.message', ''));

        return $said === ''
            ? 'The provider answered with status '.$response->status().'.'
            : $said.' (status '.$response->status().')';
    }

    /**
     * Turns the answer into a reply.
     *
     * The text is gathered from every content block rather than the first:
     * a long answer arrives split, and reading only block zero truncates it at
     * a point that depends on the provider's chunking — which looks like the
     * bot losing its train of thought mid-sentence.
     */
    protected function read(AiPrompt $prompt, Response $response): AiReply
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $usage = $this->usageOf($body);
        $content = $this->textOf($body);

        if ($content === '') {
            return AiReply::declined(AiFailure::Unparseable, 'The provider answered with an empty message.', $usage);
        }

        if (! $this->wantsJson($prompt)) {
            return AiReply::answered($content, [], $usage);
        }

        $data = $this->decode($content);

        if ($data === null) {
            return AiReply::declined(AiFailure::Unparseable, 'The answer was not the JSON object that was asked for.', $usage);
        }

        return AiReply::structured(
            $data,
            trim((string) ($data['answer'] ?? '')),
            $this->sourcesOf($data),
            $usage,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function textOf(array $body): string
    {
        $parts = [];

        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode('', $parts));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function usageOf(array $body): AiUsage
    {
        $input = (int) data_get($body, 'usage.input_tokens', 0);
        $output = (int) data_get($body, 'usage.output_tokens', 0);

        return new AiUsage($input, $output, $this->costOf($input, $output));
    }

    private function model(): string
    {
        $model = trim((string) ($this->config['model'] ?? ''));

        return $model === '' ? 'claude-sonnet-5-5' : $model;
    }
}
