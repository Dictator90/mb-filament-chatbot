<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * Everything a driver needs to carry on a conversation, as against answering
 * one question.
 *
 * The sibling of {@see AiPrompt}, and deliberately not an extension of it. A
 * prompt is a question with material behind it: ask it twice and you get the
 * same answer, which is what makes it cheap to reason about and safe to cache.
 * A turn is a position in an exchange — what was said before, what the model
 * asked to have done, what came back — and the answer depends on all of it.
 * Folding the two together would mean every driver that can only answer
 * questions pretending to hold a conversation, with the history flattened into
 * the question and the tool results flattened into prose.
 *
 * Immutable, with {@see self::with()} returning a longer turn. The agent loop
 * runs several rounds inside one visitor message, and a mutable list is how
 * round three ends up sharing a history with the retry of round two.
 */
final class AiTurn
{
    /**
     * @param  string  $instruction  what the model is and what it may do — the system message
     * @param  list<AiMessage>  $messages  the exchange so far, oldest first
     * @param  list<AiTool>  $tools  what the model may ask for; an empty list is a plain chat
     * @param  int  $maxTokens  0 leaves it to the driver's configured ceiling
     * @param  float|null  $temperature  null leaves it to the driver's configured value
     */
    public function __construct(
        public readonly string $instruction,
        public readonly array $messages = [],
        public readonly array $tools = [],
        public readonly int $maxTokens = 0,
        public readonly ?float $temperature = null,
    ) {}

    public function with(AiMessage ...$messages): self
    {
        return new self(
            instruction: $this->instruction,
            messages: [...$this->messages, ...array_values($messages)],
            tools: $this->tools,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
        );
    }

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_values(array_map(static fn (AiTool $tool): string => $tool->name, $this->tools));
    }
}
