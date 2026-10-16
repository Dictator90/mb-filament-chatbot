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
 * Any endpoint that speaks the OpenAI chat-completions shape.
 *
 * One driver covers OpenAI itself, OpenRouter, Azure, DeepSeek, Ollama, LM
 * Studio and a local vLLM, because all of them answer the same POST. That is
 * the whole reason this is the package's HTTP driver rather than a vendor SDK:
 * the SDK would add a dependency, a release cadence and a breaking change per
 * major version in exchange for a request Laravel's HTTP client already makes.
 *
 * **Nothing throws out of here.** A missing key, a refused address, a socket
 * that never opened, a 500 from the provider, a body that is not the JSON it
 * promised — every one of them returns a declined reply with a reason, because
 * the caller is a node whose author drew a second exit for exactly this. An
 * exception instead would end the visitor's turn in silence, and the fault
 * would be reported as "the bot stopped answering".
 *
 * A 5xx is reported as unavailable and a 4xx as refused, and the split earns
 * its keep in the run journal: the first is somebody else's outage and will
 * pass, the second is this installation's request being wrong and will not.
 */
final class OpenAiGateway implements AiConversationGateway
{
    use TalksToModels;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'openai';
    }

    /**
     * A key and an address, checked without leaving the process.
     *
     * Deliberately not a ping. Nodes ask this before every call, and a
     * round trip here would cost a conversation turn the latency of a request
     * that was only meant to decide whether to make one.
     */
    public function isAvailable(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '';
    }

    /**
     * The provider's own words about why it said no, with the status behind them.
     *
     * A status code alone sends whoever is debugging to a search engine. The
     * sentence beside it usually names the fix outright — "this model is no
     * longer available to new users", "user location is not supported", "you
     * exceeded your current quota" — and those three have entirely different
     * answers that a bare 404 or 400 cannot distinguish.
     *
     * Two shapes are read because providers disagree about one: OpenAI answers
     * `{"error": {...}}` and Google's compatibility endpoint answers
     * `[{"error": {...}}]`, wrapped in a list. Reading only the first would have
     * dropped the message in exactly the case this was written for.
     */
    protected function complaint(Response $response): string
    {
        $body = $response->json();
        $error = $body['error'] ?? ($body[0]['error'] ?? null);
        $said = is_array($error) ? trim((string) ($error['message'] ?? '')) : '';

        return $said === ''
            ? 'The provider answered with status '.$response->status().'.'
            : $said.' (status '.$response->status().')';
    }

    /**
     * @throws ConnectionException
     */
    protected function send(AiPrompt $prompt): Response
    {
        return $this->request()
            ->withToken($this->apiKey())
            ->post($this->baseUrl().'/chat/completions', $this->payload($prompt));
    }

    /**
     * The request body.
     *
     * JSON is asked for whenever the caller passed a schema or any context: a
     * grounded answer has to name its sources, and a source named in the middle
     * of a sentence cannot be told from a sentence that merely mentions one.
     *
     * @return array<string, mixed>
     */
    private function payload(AiPrompt $prompt): array
    {
        $payload = [
            'model' => $this->model(),
            'temperature' => $prompt->temperature ?? $this->temperature(),
            'max_tokens' => $prompt->maxTokens > 0 ? $prompt->maxTokens : $this->maxTokens(),
            'messages' => [
                ['role' => 'system', 'content' => $this->systemMessage($prompt)],
                ['role' => 'user', 'content' => $prompt->question],
            ],
        ];

        if ($this->wantsJson($prompt)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Turns the provider's answer into a reply.
     *
     * An empty body, a missing choice and unreadable JSON all land on
     * "unparseable" rather than on an empty successful answer, because an
     * answer of "" spoken to a visitor is a bot that appears to have ignored
     * them, and the second exit exists so that something else can happen
     * instead.
     */
    protected function read(AiPrompt $prompt, Response $response): AiReply
    {
        $body = $response->json();
        $usage = $this->usageOf(is_array($body) ? $body : []);
        $content = is_array($body) ? trim((string) data_get($body, 'choices.0.message.content', '')) : '';

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
     * Carries on an exchange with tools, in the chat-completions shape.
     *
     * The same endpoint that answers single questions, and the reason the agent
     * is not a Claude-only feature: OpenAI, OpenRouter, DeepSeek, Azure, a local
     * vLLM or Ollama all accept `tools` and answer with `tool_calls` in this
     * one shape. What differs from Anthropic is only the spelling — the
     * instruction is a `system` message, a requested call carries its
     * arguments as a JSON *string*, and each result is a message of its own
     * with the role `tool` rather than a block inside a user turn.
     *
     * A model behind this endpoint that has no tool support at all usually
     * answers with plain text and never asks for anything; the node then
     * behaves as a conversation without tools, which is the honest outcome.
     */
    public function converse(AiTurn $turn): AiReply
    {
        if (! $this->isAvailable()) {
            return AiReply::declined(AiFailure::NotConfigured);
        }

        try {
            $response = $this->request()
                ->withToken($this->apiKey())
                ->post($this->baseUrl().'/chat/completions', $this->exchange($turn));
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
     * @return array<string, mixed>
     */
    private function exchange(AiTurn $turn): array
    {
        $messages = [['role' => 'system', 'content' => $turn->instruction]];

        foreach ($turn->messages as $message) {
            if ($message->isToolResult()) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $message->callId,
                    'content' => $message->ok ? $message->text : 'ERROR: '.$message->text,
                ];

                continue;
            }

            if ($message->hasCalls()) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $message->text === '' ? null : $message->text,
                    'tool_calls' => array_map(static fn (AiToolCall $call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => (string) json_encode($call->input === [] ? new stdClass : $call->input, JSON_UNESCAPED_UNICODE),
                        ],
                    ], $message->calls),
                ];

                continue;
            }

            if ($message->text !== '') {
                $messages[] = ['role' => $message->role, 'content' => $message->text];
            }
        }

        $payload = [
            'model' => $this->model(),
            'max_tokens' => $turn->maxTokens > 0 ? $turn->maxTokens : $this->maxTokens(),
            'messages' => $messages,
        ];

        if ($turn->hasTools()) {
            $payload['tools'] = array_map(static fn (AiTool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->schema === [] ? ['type' => 'object', 'properties' => new stdClass] : $tool->schema,
                ],
            ], $turn->tools);
        }

        if ($turn->temperature !== null) {
            $payload['temperature'] = $turn->temperature;
        }

        return $payload;
    }

    /**
     * Tool requests first, text second — for the same reason as in the
     * Anthropic driver: reading the sentence that came alongside a request as
     * the answer ends the round before the tool runs.
     *
     * Arguments arrive as a JSON string and are decoded here; a string that is
     * not JSON becomes an empty input, which the capability gateway then
     * refuses by name rather than this driver guessing at it.
     */
    private function readExchange(Response $response): AiReply
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $usage = $this->usageOf($body);
        $message = (array) data_get($body, 'choices.0.message', []);
        $calls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (! is_array($call)) {
                continue;
            }

            $arguments = json_decode((string) data_get($call, 'function.arguments', ''), true);

            $calls[] = new AiToolCall(
                id: (string) ($call['id'] ?? ''),
                name: (string) data_get($call, 'function.name', ''),
                input: is_array($arguments) ? $arguments : [],
            );
        }

        $text = trim((string) ($message['content'] ?? ''));

        if ($calls !== []) {
            return AiReply::calling($calls, $text, $usage);
        }

        return $text === ''
            ? AiReply::declined(AiFailure::Unparseable, 'The provider answered with an empty message.', $usage)
            : AiReply::answered($text, [], $usage);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function usageOf(array $body): AiUsage
    {
        $input = (int) data_get($body, 'usage.prompt_tokens', 0);
        $output = (int) data_get($body, 'usage.completion_tokens', 0);

        return new AiUsage($input, $output, $this->costOf($input, $output));
    }

    private function model(): string
    {
        $model = trim((string) ($this->config['model'] ?? ''));

        return $model === '' ? 'gpt-4o-mini' : $model;
    }
}
