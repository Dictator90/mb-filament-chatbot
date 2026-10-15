<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Agent;

use MB\FilamentChatbot\Ai\AiUsage;

/**
 * How a turn led by the model ended, with everything the run journal needs.
 *
 * Three endings and not two. "It answered" and "it could not" are the node's
 * two exits, but between them sits the one a scenario has to route differently:
 * the model decided this needs a person. That is not a failure — it is the
 * agent doing the most useful thing it can — and reporting it as one would put
 * "the model did not answer" in the journal for every conversation that went
 * to a manager exactly as intended.
 *
 * The work log is a list rather than a count because the question asked of it
 * afterwards is always "what did the bot *do* in that conversation" — which
 * tool, with what, and what came back. A number answers none of it.
 */
final class AgentOutcome
{
    /**
     * @param  list<array<string, mixed>>  $did
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly bool $wantsHuman,
        public readonly ?string $reason,
        public readonly AiUsage $usage,
        public readonly array $did,
        public readonly int $rounds,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $did
     */
    public static function spoke(string $text, AiUsage $usage, array $did, int $rounds): self
    {
        return new self(true, trim($text), false, null, $usage, $did, $rounds);
    }

    /**
     * @param  list<array<string, mixed>>  $did
     */
    public static function wantsHuman(string $reason, string $text, AiUsage $usage, array $did, int $rounds): self
    {
        return new self(true, trim($text), true, trim($reason), $usage, $did, $rounds);
    }

    /**
     * @param  list<array<string, mixed>>  $did
     */
    public static function failed(string $reason, AiUsage $usage, array $did = [], int $rounds = 0): self
    {
        return new self(false, '', false, $reason, $usage, $did, $rounds);
    }

    /**
     * @return array<string, mixed>
     */
    public function trace(): array
    {
        return array_filter([
            'rounds' => $this->rounds,
            'reason' => $this->reason,
            'wants_human' => $this->wantsHuman ?: null,
            'did' => $this->did === [] ? null : $this->did,
            'usage' => $this->usage->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
