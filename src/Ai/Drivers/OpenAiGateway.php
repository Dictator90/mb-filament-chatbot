<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\AiUsage;
use MB\FilamentChatbot\Ai\Drivers\Concerns\TalksToModels;

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
final class OpenAiGateway implements AiGateway
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
     * Reads the JSON object out of the message.
     *
     * Fenced code blocks are stripped first because models wrap JSON in them
     * whatever the instruction said, and treating that as unreadable would push
     * perfectly good answers down the "could not" exit for a decoration.
     *
     * @return array<string, mixed>|null
     */
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
