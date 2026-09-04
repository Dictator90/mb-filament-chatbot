<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * The record of what the bot did, and why.
 *
 * Kept because the question people bring to a bot is never "did it run" but
 * "why did it say that". Without a trace the answer has to be reconstructed by
 * reading the scenario and guessing which way each branch went, which is
 * exactly the reasoning that produced the bug in the first place.
 *
 * Rows are buffered and written once at the end of a turn rather than as they
 * happen. A turn can walk dozens of nodes, and an insert per node would put the
 * journal's cost on the visitor's reply — the journal is for us, and it must
 * never be the reason a person waits.
 */
class RunJournal
{
    private ?Run $run = null;

    private int $seq = 0;

    private int $spoken = 0;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /**
     * Starts a run, closing any previous one first.
     *
     * A conversation has at most one open run: two would make "what happened
     * last" ambiguous exactly when someone is trying to find out.
     */
    public function open(Conversation $conversation, Scenario $scenario): Run
    {
        $this->flush();

        $this->run = Run::create([
            'conversation_id' => $conversation->getKey(),
            'scenario_id' => $scenario->getKey(),
            'version_id' => $conversation->bot_version_id,
            'scenario_slug' => $scenario->slug,
            'channel' => $conversation->channel,
            'started_at' => now(),
            'outcome' => RunOutcome::Running,
        ]);

        $this->seq = 0;
        $this->spoken = 0;

        $conversation->forceFill(['bot_run_id' => $this->run->getKey()])->save();

        return $this->run;
    }

    /**
     * Attaches to a run that is already open, so that a resumed delay or a
     * second turn continues the same story instead of starting a new one.
     */
    public function attach(?Run $run): void
    {
        $this->run = $run;
        $this->seq = (int) ($run?->steps_count ?? 0);
        $this->spoken = (int) ($run?->said_count ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Scenario $scenario,
        Step $step,
        ?string $said,
        string $outcome,
        array $payload = [],
    ): void {
        if ($this->run === null) {
            return;
        }

        $this->buffer[] = [
            'run_id' => $this->run->getKey(),
            'seq' => ++$this->seq,
            'step_key' => $step->key,
            'step_type' => $step->type,
            'scenario_slug' => $scenario->slug,
            'said' => $said,
            'outcome' => $outcome,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ];
    }

    public function spoke(): void
    {
        $this->spoken++;
    }

    public function close(RunOutcome $outcome): void
    {
        if ($this->run === null) {
            return;
        }

        $this->flush();

        $this->run->forceFill([
            'ended_at' => now(),
            'outcome' => $outcome,
        ])->save();

        $this->run = null;
    }

    /**
     * Writes the buffered rows and updates the counters.
     *
     * Safe to call more than once and safe to call with nothing buffered: the
     * engine flushes at every exit from a turn, and there are several.
     */
    public function flush(): void
    {
        if ($this->run === null) {
            return;
        }

        if ($this->buffer !== []) {
            RunStep::insert($this->buffer);
            $this->buffer = [];
        }

        $this->run->forceFill([
            'steps_count' => $this->seq,
            'said_count' => $this->spoken,
        ])->save();
    }

    public function current(): ?Run
    {
        return $this->run;
    }
}
