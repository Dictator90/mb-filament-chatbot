<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\AiUsage;
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
final class OpenAiGateway implements AiGateway
{
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

    public function ask(AiPrompt $prompt): AiReply
    {
        if (! $this->isAvailable()) {
            return AiReply::declined(AiFailure::NotConfigured);
        }

        try {
            $response = $this->send($prompt);
        } catch (ConnectionException $exception) {
            return AiReply::declined($this->connectionFailure($exception), $exception->getMessage());
        } catch (Throwable $exception) {
            return AiReply::declined(AiFailure::Unavailable, $exception::class.': '.$exception->getMessage());
        }

        if (! $response->successful()) {
            return AiReply::declined(
                $response->status() >= 500 ? AiFailure::Unavailable : AiFailure::Refused,
                'The provider answered with status '.$response->status().'.',
            );
        }

        return $this->read($prompt, $response);
    }

    /**
     * @throws ConnectionException
     */
    private function send(AiPrompt $prompt): Response
    {
        return Http::withToken($this->apiKey())
            ->timeout($this->timeout())
            ->acceptJson()
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
     * The instruction, the material and the shape of the answer, in that order.
     *
     * The rule about sources is repeated here in the prompt and enforced again
     * by the caller against the keys it passed. Asking politely is not a
     * control — a model that names an article nobody gave it has invented both
     * the article and the answer — so the sentence below is only there to make
     * the honest case easy, and the check upstream is what makes the dishonest
     * one harmless.
     */
    private function systemMessage(AiPrompt $prompt): string
    {
        $parts = [trim($prompt->instruction)];

        if ($prompt->hasContext()) {
            $parts[] = "Answer only from the material below. Each fragment starts with its key in square brackets.\n\n".$prompt->contextText();
        }

        if ($this->wantsJson($prompt)) {
            $parts[] = 'Answer with a single JSON object and nothing else. '.$this->shapeSentence($prompt);
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function shapeSentence(AiPrompt $prompt): string
    {
        $fields = [];

        foreach ($prompt->schema ?? ['answer' => 'the reply, in the visitor\'s language'] as $name => $description) {
            $fields[] = $description === '' ? '"'.$name.'"' : '"'.$name.'": '.$description;
        }

        if ($prompt->hasContext()) {
            $fields[] = '"sources": the keys of the fragments you used, as an array of strings; use an empty array when the material does not answer the question';
        }

        return 'Keys: '.implode('; ', $fields).'.';
    }

    private function wantsJson(AiPrompt $prompt): bool
    {
        return $prompt->wantsStructure() || $prompt->hasContext();
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
    private function read(AiPrompt $prompt, Response $response): AiReply
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
    private function decode(string $content): ?array
    {
        if (str_starts_with($content, '```')) {
            $content = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/iu', '', $content));
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function sourcesOf(array $data): array
    {
        $sources = $data['sources'] ?? [];

        if (is_string($sources)) {
            $sources = [$sources];
        }

        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) (is_scalar($key) ? $key : '')), $sources),
            static fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function usageOf(array $body): AiUsage
    {
        return new AiUsage(
            (int) data_get($body, 'usage.prompt_tokens', 0),
            (int) data_get($body, 'usage.completion_tokens', 0),
        );
    }

    /**
     * A timeout and a refused connection arrive as the same exception class,
     * and they are told apart by the message because that is the only thing
     * the client gives us. Wrong either way it is still a decline; the split
     * only decides which sentence the run journal shows.
     */
    private function connectionFailure(ConnectionException $exception): AiFailure
    {
        return str_contains(mb_strtolower($exception->getMessage()), 'timed out')
            || str_contains(mb_strtolower($exception->getMessage()), 'timeout')
            ? AiFailure::Timeout
            : AiFailure::Unavailable;
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    private function model(): string
    {
        $model = trim((string) ($this->config['model'] ?? ''));

        return $model === '' ? 'gpt-4o-mini' : $model;
    }

    private function timeout(): int
    {
        $timeout = (int) ($this->config['timeout'] ?? 20);

        return $timeout > 0 ? $timeout : 20;
    }

    private function temperature(): float
    {
        return (float) ($this->config['temperature'] ?? 0.2);
    }

    private function maxTokens(): int
    {
        $tokens = (int) ($this->config['max_tokens'] ?? 600);

        return $tokens > 0 ? $tokens : 600;
    }
}
