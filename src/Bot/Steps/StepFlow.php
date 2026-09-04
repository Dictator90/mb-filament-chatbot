<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps;

/**
 * What the engine does once a step has run.
 *
 * A closed set, and it stays closed. A step decides what it did; where the
 * conversation goes next is the engine's business, and the two are separated so
 * that a host-written step cannot invent a transition the engine has never
 * heard of. Every case here already exists in the walker, so adding a step type
 * never means touching the engine.
 */
enum StepFlow: string
{
    /**
     * Follow the step's own "next" output.
     *
     * Symbolic rather than an explicit key on purpose: a step should not have to
     * know how its outputs are stored. Two places resolving next_key would drift
     * the moment the graph format changes.
     */
    case Next = 'next';

    /** Follow the second output of a two-output step — the "could not" branch. */
    case Second = 'second';

    /** Follow a named step, chosen by the step itself (branching by option). */
    case Goto = 'goto';

    /** Stop here and wait for the visitor to reply to this very step. */
    case Wait = 'wait';

    /**
     * Hand the conversation to a human and fall silent.
     *
     * Terminal for the bot, not for the conversation: the operator carries on
     * in the same thread.
     */
    case Handoff = 'handoff';

    /** Say goodbye and close. The next incoming message reopens it. */
    case Close = 'close';

    /** Continue in another scenario and never come back. */
    case Jump = 'jump';

    /**
     * Run another scenario and return here with its result.
     *
     * Distinct from Jump because the return is the whole point: shared work —
     * identifying the visitor, collecting a phone number — is written once and
     * called from everywhere, instead of being copied into each scenario and
     * drifting apart.
     */
    case Call = 'call';

    /** Finish the called scenario and hand a value back to the caller. */
    case Returns = 'return';

    /**
     * Pause, then resume at a named step.
     *
     * A queued job, never a sleeping request. Three bot messages arriving in the
     * same second is the first thing that gives a bot away, and holding the
     * request open to fix that would hold a worker open too.
     */
    case Delay = 'delay';
}
