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
     * @param  array{action: string, input: array<string, mixed>}|null  $pending
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly bool $wantsHuman,
        public readonly ?string $reason,
        public readonly AiUsage $usage,
        public readonly array $did,
        public readonly int $rounds,
        public readonly bool $passedOn = false,
        public readonly ?array $pending = null,
    ) {}

    /**
     * The model asked for an action that needs the visitor's yes, and has
     * worded the question; the action itself has not run.
     *
     * @param  array{action: string, input: array<string, mixed>}  $pending
     * @param  list<array<string, mixed>>  $did
     */
    public static function awaitsApproval(array $pending, string $text, AiUsage $usage, array $did, int $rounds): self
    {
        return new self(true, trim($text), false, null, $usage, $did, $rounds, false, $pending);
    }

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
     * The model says its part is done and the conversation may carry on.
     *
     * A success like `spoke()`, and told apart from it because the node's first
     * exit hangs on exactly this difference: an ordinary answer leaves the
     * conversation with the model, this one passes it to whatever the author
     * drew next.
     *
     * @param  list<array<string, mixed>>  $did
     */
    public static function passedOn(string $note, string $text, AiUsage $usage, array $did, int $rounds): self
    {
        return new self(true, trim($text), false, trim($note) === '' ? null : trim($note), $usage, $did, $rounds, true);
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
            'passed_on' => $this->passedOn ?: null,
            'awaits_approval' => $this->pending['action'] ?? null,
            'did' => $this->did === [] ? null : $this->did,
            'usage' => $this->usage->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
