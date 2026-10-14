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
final class AnthropicGateway implements AiGateway
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
        return $this->request()
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => self::API_VERSION,
            ])
            ->post($this->baseUrl().'/messages', $this->payload($prompt));
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
        return [
            'model' => $this->model(),
            'max_tokens' => $prompt->maxTokens > 0 ? $prompt->maxTokens : $this->maxTokens(),
            'temperature' => $prompt->temperature ?? $this->temperature(),
            'system' => $this->systemMessage($prompt),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->question],
            ],
        ];
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
