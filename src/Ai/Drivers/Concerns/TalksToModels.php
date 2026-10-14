<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use Throwable;

/**
 * Everything two HTTP drivers do the same way.
 *
 * What differs between an OpenAI-shaped endpoint and Anthropic's is one request
 * and one response shape. What they share is the whole of the rest: composing
 * the instruction, asking for JSON, unwrapping a fenced code block, reading the
 * sources back, pricing the tokens, and turning every possible failure into a
 * declined reply instead of an exception.
 *
 * Shared rather than copied because the copy is what drifts. The fenced-block
 * rule below was added after models wrapped JSON in ``` whatever the prompt
 * said; a second driver with its own decoder would have met the same models and
 * learned the same lesson a year later, in production.
 */
trait TalksToModels
{
    /**
     * @throws ConnectionException
     */
    abstract protected function send(AiPrompt $prompt): Response;

    abstract protected function read(AiPrompt $prompt, Response $response): AiReply;

    abstract protected function complaint(Response $response): string;

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
                $this->complaint($response),
            );
        }

        return $this->read($prompt, $response);
    }

    /**
     * The request, with this installation's proxy on it when it has one.
     *
     * The case is a host that cannot reach the provider directly — the same
     * blocked route that made messengers need one — and it fails as a silent
     * timeout rather than a refusal, which on screen reads as the model being
     * slow. Per driver first, because an installation may reach one provider
     * directly and another only through a tunnel.
     */
    protected function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout())->acceptJson();
        $proxy = $this->proxy();

        return $proxy === '' ? $request : $request->withOptions(['proxy' => $proxy]);
    }

    /**
     * Public for the same reason `ChannelHttp::proxyFor()` is: which route a
     * call will take is otherwise invisible from outside — the option is merged
     * into the client and never appears on the request anybody can inspect.
     */
    public function proxy(): string
    {
        $own = trim((string) ($this->config['proxy'] ?? ''));

        return $own === '' ? trim((string) config('chatbot.ai.proxy', '')) : $own;
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
    protected function systemMessage(AiPrompt $prompt): string
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

    protected function shapeSentence(AiPrompt $prompt): string
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

    protected function wantsJson(AiPrompt $prompt): bool
    {
        return $prompt->wantsStructure() || $prompt->hasContext();
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
    protected function decode(string $content): ?array
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
    protected function sourcesOf(array $data): array
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
     * What those tokens cost, at the price this installation was told.
     *
     * **No endpoint in this shape returns a price**, and the price is not the
     * package's to know: the same request costs one thing on OpenAI, another
     * through OpenRouter and nothing at all against a model running on the
     * host's own machine. So the figures come from this driver's own
     * configuration, stated per million tokens the way every provider quotes
     * them, and unpriced means zero rather than a guess — the money ceiling then
     * never stops the month, which somebody can see and set, instead of stopping
     * it against a number nobody agreed to.
     *
     * The conversion needs no scaling factor because the two units cancel:
     * cost is kept in millionths and prices are quoted per million tokens, so a
     * token priced at P per million costs exactly P micro-units.
     */
    protected function costOf(int $inputTokens, int $outputTokens): int
    {
        $cost = $inputTokens * $this->price('price_per_million_input')
            + $outputTokens * $this->price('price_per_million_output');

        return max(0, (int) round($cost));
    }

    protected function price(string $key): float
    {
        return max(0.0, (float) ($this->config[$key] ?? 0));
    }

    /**
     * A timeout and a refused connection arrive as the same exception class,
     * and they are told apart by the message because that is the only thing
     * the client gives us. Wrong either way it is still a decline; the split
     * only decides which sentence the run journal shows.
     */
    protected function connectionFailure(ConnectionException $exception): AiFailure
    {
        return str_contains(mb_strtolower($exception->getMessage()), 'timed out')
            || str_contains(mb_strtolower($exception->getMessage()), 'timeout')
            ? AiFailure::Timeout
            : AiFailure::Unavailable;
    }

    protected function baseUrl(): string
    {
        return rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
    }

    protected function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    protected function timeout(): int
    {
        $timeout = (int) ($this->config['timeout'] ?? 20);

        return $timeout > 0 ? $timeout : 20;
    }

    protected function temperature(): float
    {
        return (float) ($this->config['temperature'] ?? 0.2);
    }

    protected function maxTokens(): int
    {
        $tokens = (int) ($this->config['max_tokens'] ?? 600);

        return $tokens > 0 ? $tokens : 600;
    }
}
