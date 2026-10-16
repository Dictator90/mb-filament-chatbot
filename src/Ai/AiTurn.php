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
     * @param  int  $stable  how many leading bytes of the instruction stay the same from turn to turn
     */
    public function __construct(
        public readonly string $instruction,
        public readonly array $messages = [],
        public readonly array $tools = [],
        public readonly int $maxTokens = 0,
        public readonly ?float $temperature = null,
        public readonly int $stable = 0,
    ) {}

    public function with(AiMessage ...$messages): self
    {
        return new self(
            instruction: $this->instruction,
            messages: [...$this->messages, ...array_values($messages)],
            tools: $this->tools,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            stable: $this->stable,
        );
    }

    /**
     * The instruction cut where it stops being the same every turn.
     *
     * An agent's instruction is the author's text followed by what changes per
     * turn — the fields still missing, the running summary. A provider that
     * caches a prefix can only reuse the part that is identical, and caching
     * the whole string would miss on every turn the list of missing fields got
     * shorter. Drivers without a cache ignore this and send the instruction whole.
     *
     * @return array{0: string, 1: string}
     */
    public function instructionParts(): array
    {
        $stable = max(0, min($this->stable, strlen($this->instruction)));

        if ($stable === 0) {
            return [$this->instruction, ''];
        }

        return [substr($this->instruction, 0, $stable), substr($this->instruction, $stable)];
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
