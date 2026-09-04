<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps;

use InvalidArgumentException;

/**
 * What a step reports back to the engine.
 *
 * Steps never move the conversation themselves. They say what happened and hand
 * that back; the engine decides where the walk goes and writes the trace. That
 * split is why a host can add a step without the engine knowing it exists, and
 * why a step cannot leave the conversation in a state the engine did not author
 * — parked on a scenario that was deleted, waiting on a step that never asked
 * anything.
 *
 * The trace fields are not decoration. A run journal that says only "step ran"
 * answers half the question people bring to it: the step may well have run and
 * still have written nothing, because the answer did not parse. Steps say which.
 */
final class StepResult
{
    /**
     * @param  array<string, mixed>  $trace
     */
    private function __construct(
        public readonly StepFlow $flow,
        public readonly ?string $target = null,
        public readonly ?int $seconds = null,
        public readonly mixed $value = null,
        public readonly ?string $outcome = null,
        public readonly array $trace = [],
        public readonly bool $spoke = false,
    ) {}

    /** Follow the step's own "next" output. */
    public static function next(): self
    {
        return new self(StepFlow::Next);
    }

    /**
     * Take the second output — the branch that means "could not".
     *
     * Every two-output step routes failure here, and the labels differ only in
     * wording: no answer found, the model declined, the form gave up, nothing
     * matched. Collapsing them into one branch is deliberate — they all lead to
     * a person, and four arrows out of one node would say the same thing four
     * times.
     */
    public static function second(): self
    {
        return new self(StepFlow::Second);
    }

    /**
     * Continue at a named step.
     *
     * A null key is legitimate and means the branch simply ends here — an
     * option the author left unconnected. The engine closes the run as a dead
     * end and says so in the journal, rather than treating it as a crash.
     */
    public static function goto(?string $stepKey): self
    {
        return new self(StepFlow::Goto, target: $stepKey);
    }

    public static function wait(): self
    {
        return new self(StepFlow::Wait);
    }

    public static function handoff(): self
    {
        return new self(StepFlow::Handoff);
    }

    public static function close(): self
    {
        return new self(StepFlow::Close);
    }

    public static function jump(string $scenarioSlug): self
    {
        return new self(StepFlow::Jump, target: $scenarioSlug);
    }

    public static function call(string $scenarioSlug): self
    {
        return new self(StepFlow::Call, target: $scenarioSlug);
    }

    public static function returns(mixed $value = null): self
    {
        return new self(StepFlow::Returns, value: $value);
    }

    /**
     * @param  int  $seconds  how long to wait before resuming at this step's next output
     */
    public static function delay(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('A delay shorter than a second is a step that does nothing.');
        }

        return new self(StepFlow::Delay, seconds: $seconds);
    }

    /**
     * Records what the step did, for the run journal.
     *
     * The outcome is one of the constants on the run-step model, and the payload
     * carries the few facts someone reading the journal actually asks for — the
     * variable that was written, the number of records found, the reason a
     * branch was taken.
     *
     * @param  array<string, mixed>  $payload
     */
    public function traced(string $outcome, array $payload = []): self
    {
        return new self(
            $this->flow,
            $this->target,
            $this->seconds,
            $this->value,
            $outcome,
            $payload,
            $this->spoke,
        );
    }

    /**
     * Marks that the step said something to the visitor.
     *
     * The engine needs this to tell a silent turn from a spoken one: a turn that
     * walked ten steps and said nothing means the visitor is staring at an empty
     * chat, and that is worth knowing without reading the whole trace.
     */
    public function spoken(bool $spoke = true): self
    {
        return new self(
            $this->flow,
            $this->target,
            $this->seconds,
            $this->value,
            $this->outcome,
            $this->trace,
            $spoke,
        );
    }

    public function ends(): bool
    {
        return in_array($this->flow, [StepFlow::Handoff, StepFlow::Close, StepFlow::Returns], true);
    }

    public function pauses(): bool
    {
        return in_array($this->flow, [StepFlow::Wait, StepFlow::Delay], true);
    }
}
