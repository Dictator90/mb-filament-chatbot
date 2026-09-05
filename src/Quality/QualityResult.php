<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Quality;

use MB\FilamentChatbot\Enums\QualityOutcome;

/**
 * What one rehearsal produced.
 *
 * Immutable and built through named constructors, so that a result can only
 * exist in one of the three shapes that mean something: a pass with nothing to
 * say, a failure that names every expectation it broke, and a run that never
 * happened. Nothing may assemble a fourth — a "failure" with no mismatch on it
 * is a red badge nobody can act on, and that is the shape a plain constructor
 * would invite.
 *
 * A broken result still carries whatever transcript it managed to collect. The
 * engine may have thrown on the fourth line of five, and those three replies are
 * the only description anybody will get of where it stopped.
 */
final class QualityResult
{
    /**
     * @param  list<array{kind: string, expected: string, actual: string}>  $mismatches
     * @param  list<array{from: string, body: string}>  $transcript
     * @param  list<string>  $visited
     */
    private function __construct(
        private readonly QualityOutcome $outcome,
        private readonly array $mismatches,
        private readonly array $transcript,
        private readonly array $visited,
        private readonly ?string $reason,
    ) {}

    /**
     * @param  list<array{from: string, body: string}>  $transcript
     * @param  list<string>  $visited
     */
    public static function passed(array $transcript, array $visited): self
    {
        return new self(QualityOutcome::Passed, [], $transcript, $visited, null);
    }

    /**
     * @param  list<array{kind: string, expected: string, actual: string}>  $mismatches
     * @param  list<array{from: string, body: string}>  $transcript
     * @param  list<string>  $visited
     */
    public static function failed(array $mismatches, array $transcript, array $visited): self
    {
        return new self(QualityOutcome::Failed, $mismatches, $transcript, $visited, null);
    }

    /**
     * A rehearsal that could not be held.
     *
     * The reason is repeated into the mismatch list rather than kept only on the
     * result, because the panel reads `last_mismatches` and would otherwise show
     * a red row with an empty explanation — which reads as a bot that said the
     * wrong thing, and sends whoever opens it looking for a bug in a conversation
     * that never ran.
     *
     * @param  list<array{from: string, body: string}>  $transcript
     * @param  list<string>  $visited
     */
    public static function broken(string $reason, array $transcript = [], array $visited = []): self
    {
        return new self(
            QualityOutcome::Broken,
            [['kind' => 'broken', 'expected' => 'a check that can run', 'actual' => $reason]],
            $transcript,
            $visited,
            $reason,
        );
    }

    public function outcome(): QualityOutcome
    {
        return $this->outcome;
    }

    /**
     * @return list<array{kind: string, expected: string, actual: string}>
     */
    public function mismatches(): array
    {
        return $this->mismatches;
    }

    /**
     * @return list<array{from: string, body: string}>
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * The node keys the conversation genuinely passed through, in order.
     *
     * @return list<string>
     */
    public function visited(): array
    {
        return $this->visited;
    }

    /**
     * Why the rehearsal could not be held, or null when it was.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Only a pass is passing.
     *
     * Broken deliberately answers false. A check that could not run has proved
     * nothing, and a release pipeline that treated "could not run" as "fine"
     * would go green on the day the scenario under test was deleted — the one
     * day the check existed for.
     */
    public function isPassing(): bool
    {
        return $this->outcome === QualityOutcome::Passed;
    }
}
