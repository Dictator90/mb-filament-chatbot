<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * What one call to a model cost, in tokens.
 *
 * Carried on every reply, including the ones that failed after the request had
 * already left, because a refused answer is still billed. Reporting usage only
 * on success is how an installation ends up with a budget page that says less
 * than the provider's invoice does.
 *
 * Tokens and not money: the price per token belongs to the provider and the
 * model and changes without this package hearing about it, so converting here
 * would produce a number that looks authoritative and is quietly wrong.
 */
final class AiUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
