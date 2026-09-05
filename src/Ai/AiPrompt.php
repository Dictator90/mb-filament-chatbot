<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * Everything a driver needs to ask a model one question.
 *
 * A value object rather than a string, because the four parts are treated
 * differently by every provider worth supporting: the instruction belongs in a
 * system role, the question in a user one, the context has to be labelled so
 * that the model can name which fragment it used, and the schema decides
 * whether the answer is asked for as prose or as JSON. Flattened into one
 * string ahead of the driver, all four arrive as an undifferentiated wall of
 * text and each driver has to guess the seams back out of it.
 *
 * **Context fragments carry a key, and that key is the point.** A model that
 * answers from the agency's own material has to say which piece it used, and
 * the caller checks that claim against the keys it passed in. Without keys the
 * check degrades into matching sentences, which is exactly the check that
 * cannot be made reliably.
 */
final class AiPrompt
{
    /**
     * @param  string  $instruction  what the model is, and what it may do — sent as the system message
     * @param  string  $question  the thing being asked, usually the visitor's own words
     * @param  list<array{key: string, title: string, text: string}>  $context  material the answer must come from
     * @param  array<string, string>|null  $schema  expected field names mapped to a short description; null asks for prose
     * @param  int  $maxTokens  0 leaves it to the driver's configured ceiling
     * @param  float|null  $temperature  null leaves it to the driver's configured value
     */
    public function __construct(
        public readonly string $instruction,
        public readonly string $question,
        public readonly array $context = [],
        public readonly ?array $schema = null,
        public readonly int $maxTokens = 0,
        public readonly ?float $temperature = null,
    ) {}

    public function hasContext(): bool
    {
        return $this->context !== [];
    }

    public function wantsStructure(): bool
    {
        return $this->schema !== null && $this->schema !== [];
    }

    /**
     * The keys of the fragments handed to the model.
     *
     * The caller's allow-list: an answer may only claim sources from here, and
     * anything else it names is treated as a source it invented.
     *
     * @return list<string>
     */
    public function contextKeys(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $fragment): string => trim((string) ($fragment['key'] ?? '')),
            $this->context,
        ), static fn (string $key): bool => $key !== ''));
    }

    /**
     * The context rendered for a text-only provider interface.
     *
     * Fragments are numbered by their own key rather than by position, because
     * the model is asked to name the key back and a positional number would be
     * meaningless the moment the candidate list changes between two turns.
     */
    public function contextText(): string
    {
        $blocks = [];

        foreach ($this->context as $fragment) {
            $key = trim((string) ($fragment['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $blocks[] = '['.$key.'] '.trim((string) ($fragment['title'] ?? ''))."\n".trim((string) ($fragment['text'] ?? ''));
        }

        return implode("\n\n", $blocks);
    }
}
