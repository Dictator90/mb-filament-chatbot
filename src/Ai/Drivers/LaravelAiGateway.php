<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers;

use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use Throwable;

/**
 * Talks to a model through laravel/ai, when the host has installed it.
 *
 * **The package does not require laravel/ai, and this driver is why it does not
 * have to.** Requiring it would be a real cost on every installation: it is
 * still 0.x with a provider surface that moves between releases, it pulls in
 * aws/aws-sdk-php outright, and its own constraints raise this package's floor
 * to a recent Laravel 12 patch. An installation that only ever posts to one
 * OpenAI-compatible endpoint would carry all of that to use none of it, and
 * would break on the day a 0.x release does.
 *
 * So the dependency stays a suggestion, and a driver whose library is not there
 * simply reports itself unavailable. That is not a special case anywhere else
 * in the package: it is the same branch as an unset key, a provider outage or a
 * refusal, and the scenario pointed at it degrades exactly as it would for any
 * of those. A hard require would have turned "somebody chose a driver they did
 * not install" into a fatal on a customer's first message.
 *
 * The call itself is probed rather than written against one release. Method
 * names in a 0.x package are not a contract, and a chain hard-coded today
 * throws a fatal on the host's next composer update — inside a conversation.
 * Nothing found and nothing thrown both come back as a decline.
 */
final class LaravelAiGateway implements AiGateway
{
    /** The facade the library exposes; its presence is the installation check. */
    private const FACADE = 'Laravel\Ai\Facades\AI';

    /** Entry points seen across 0.x for choosing a provider. */
    private const PROVIDER_METHODS = ['provider', 'using', 'driver'];

    /** Entry points seen across 0.x for choosing a model. */
    private const MODEL_METHODS = ['model', 'usingModel'];

    /** Entry points seen across 0.x for asking for plain text back. */
    private const GENERATE_METHODS = ['generateText', 'text', 'generate', 'prompt', 'ask'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'laravel-ai';
    }

    /**
     * Whether the library is installed at all.
     *
     * class_exists and nothing else: a configured provider name is worthless
     * without the code behind it, and asking the container for a facade that
     * was never registered is how this would throw instead of declining.
     */
    public function isAvailable(): bool
    {
        return class_exists(self::FACADE);
    }

    public function ask(AiPrompt $prompt): AiReply
    {
        if (! $this->isAvailable()) {
            return AiReply::declined(
                AiFailure::NotConfigured,
                'The laravel/ai package is not installed, so this driver cannot be used.',
            );
        }

        try {
            $text = $this->generate($this->instructionFor($prompt), $prompt->question);
        } catch (Throwable $exception) {
            return AiReply::declined(AiFailure::Unavailable, $exception::class.': '.$exception->getMessage());
        }

        if ($text === null) {
            return AiReply::declined(
                AiFailure::Unavailable,
                'The installed laravel/ai release does not expose an entry point this driver recognises.',
            );
        }

        $text = trim($text);

        if ($text === '') {
            return AiReply::declined(AiFailure::Unparseable, 'The provider answered with an empty message.');
        }

        if (! $this->wantsJson($prompt)) {
            return AiReply::answered($text);
        }

        $data = $this->decode($text);

        if ($data === null) {
            return AiReply::declined(AiFailure::Unparseable, 'The answer was not the JSON object that was asked for.');
        }

        return AiReply::structured($data, trim((string) ($data['answer'] ?? '')), $this->sourcesOf($data));
    }

    /**
     * Everything the model is told, as one system instruction.
     *
     * Context and shape are folded in here rather than sent as separate
     * messages because the shape of a multi-message call is the part of the 0.x
     * surface that has moved most, and one string is the only argument every
     * release has accepted.
     */
    private function instructionFor(AiPrompt $prompt): string
    {
        $parts = [trim($prompt->instruction)];

        if ($prompt->hasContext()) {
            $parts[] = "Answer only from the material below. Each fragment starts with its key in square brackets.\n\n".$prompt->contextText();
        }

        if ($this->wantsJson($prompt)) {
            $fields = [];

            foreach ($prompt->schema ?? ['answer' => 'the reply, in the visitor\'s language'] as $name => $description) {
                $fields[] = $description === '' ? '"'.$name.'"' : '"'.$name.'": '.$description;
            }

            if ($prompt->hasContext()) {
                $fields[] = '"sources": the keys of the fragments you used, as an array of strings';
            }

            $parts[] = 'Answer with a single JSON object and nothing else. Keys: '.implode('; ', $fields).'.';
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Walks the library's fluent chain by probing for method names.
     *
     * Null means "this release does not look like anything this driver knows",
     * which the caller turns into a decline rather than a fatal.
     */
    private function generate(string $instruction, string $question): ?string
    {
        $facade = self::FACADE;
        $pending = $facade::getFacadeRoot();

        if (! is_object($pending)) {
            return null;
        }

        if ($this->providerName() !== '') {
            $pending = $this->objectFrom($this->call($pending, self::PROVIDER_METHODS, [$this->providerName()]), $pending);
        }

        if ($this->modelName() !== '') {
            $pending = $this->objectFrom($this->call($pending, self::MODEL_METHODS, [$this->modelName()]), $pending);
        }

        $answer = $this->call($pending, self::GENERATE_METHODS, [trim($instruction."\n\n".$question)]);

        return $this->textOf($answer);
    }

    /**
     * Keeps the chain going when a step of it was not there.
     *
     * A release that has no provider() still has a default provider, and
     * dropping the whole call because one optional narrowing method is missing
     * would decline an installation that works.
     */
    private function objectFrom(mixed $result, object $fallback): object
    {
        return is_object($result) ? $result : $fallback;
    }

    /**
     * @param  list<string>  $methods
     * @param  list<mixed>  $arguments
     */
    private function call(object $target, array $methods, array $arguments): mixed
    {
        foreach ($methods as $method) {
            if (method_exists($target, $method)) {
                return $target->{$method}(...$arguments);
            }
        }

        return null;
    }

    /**
     * The text out of whatever the library handed back.
     *
     * Releases have returned a string, an object with a text property and an
     * object that stringifies; all three are read here so that a working
     * installation is not declined over the shape of its return value.
     */
    private function textOf(mixed $answer): ?string
    {
        if (is_string($answer)) {
            return $answer;
        }

        if (is_array($answer)) {
            $text = $answer['text'] ?? $answer['content'] ?? null;

            return is_string($text) ? $text : null;
        }

        if (! is_object($answer)) {
            return null;
        }

        foreach (['text', 'content'] as $property) {
            if (isset($answer->{$property}) && is_string($answer->{$property})) {
                return $answer->{$property};
            }
        }

        return method_exists($answer, '__toString') ? (string) $answer : null;
    }

    private function wantsJson(AiPrompt $prompt): bool
    {
        return $prompt->wantsStructure() || $prompt->hasContext();
    }

    /**
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

    private function providerName(): string
    {
        return trim((string) ($this->config['provider'] ?? ''));
    }

    private function modelName(): string
    {
        return trim((string) ($this->config['model'] ?? ''));
    }
}
