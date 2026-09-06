<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * What one call to a model cost: the tokens the provider counted, and what this
 * installation prices them at.
 *
 * Carried on every reply, including the ones that failed after the request had
 * already left, because a refused answer is still billed. Reporting usage only
 * on success is how an installation ends up with a budget page that says less
 * than the provider's invoice does.
 *
 * **The money is filled in by the driver and by nothing above it.** The price
 * per token belongs to a provider and a model, and the driver is the only thing
 * in the package that knows which of each answered; a node or a budget guard
 * working it out would be guessing at the exact number a monthly ceiling is then
 * enforced on. Where nobody has priced the model the cost is zero, and zero is
 * the honest answer — the cost ceiling simply never fires, which is visible,
 * whereas an invented price is wrong on every invoice and looks authoritative
 * while it is.
 *
 * Micro-units of the installation's own currency, matching `cost_micro` on the
 * budget period: one reply costs a fraction of a cent, and a float accumulated
 * across a month of calls stops agreeing with the provider's invoice.
 */
final class AiUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $costMicro = 0,
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
     * @return array{input_tokens: int, output_tokens: int, cost_micro: int}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_micro' => $this->costMicro,
        ];
    }
}
