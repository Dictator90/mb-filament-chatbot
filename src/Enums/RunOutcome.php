<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a run of a scenario ended.
 *
 * The set is derived from the places in the engine where a run is actually
 * closed, not invented against a hypothetical future. A spare value here is
 * worse than a missing one: it promises whoever is debugging an outcome the
 * code will never write, and they will spend an afternoon looking for it.
 *
 * There is deliberately no "answer not understood" outcome. Failing to match an
 * answer makes the bot ask again and the conversation continues, so that is the
 * outcome of a step and not of a run. Merging the two would close runs at the
 * exact moment they are getting started.
 */
enum RunOutcome: string implements HasColor, HasLabel
{
    /**
     * Still going: waiting for an answer, paused, or showing a choice. The one
     * value for which ended_at is empty.
     */
    case Running = 'running';

    /** A human was called in — by a handoff step, by request, or by a guard. */
    case HandedOff = 'handed_off';

    /** Reached a step that closes the conversation. */
    case Closed = 'closed';

    /**
     * The scenario ran out: the last step had no next step, so nobody was
     * called and nothing was asked.
     *
     * This is not a code failure but a flaw in the scenario, and the two have
     * to stay distinguishable. The engine still calls a human here, so
     * recording it as HandedOff would be truthful about the conversation and a
     * lie about the design — it would hide the single most common mistake an
     * author makes, a branch left dangling, behind an outcome that looks
     * intentional. Nobody ever learns about the dead end.
     */
    case DeadEnd = 'dead_end';

    /**
     * Hit the per-turn step limit, which is almost always a loop in the
     * transitions. Separate from DeadEnd for the same reason: one is fixed by
     * drawing a missing arrow, the other by removing one.
     */
    case StepLimit = 'step_limit';

    /**
     * The step the conversation was standing on disappeared — the scenario was
     * switched off, or the node deleted, while the person was still thinking.
     * Not a failure: scenarios are edited from the panel while the bot is
     * running. But the conversation does break, and this outcome is how many
     * people that edit cost an answer becomes visible.
     */
    case Lost = 'lost';

    /**
     * The engine threw on this run. The record is still closed: an open run
     * would read as "in progress" forever, and a crashing scenario would look
     * like a working one in every report.
     */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.run_outcome.'.$this->value);
    }

    /**
     * Red is reserved for what the author has to fix, amber for what merely
     * left the bot. Somebody scanning a journal of a thousand runs is looking
     * for exactly those two colours, so spending red on an ordinary handoff
     * would make the broken scenarios invisible among the working ones.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::HandedOff => 'warning',
            self::Closed => 'success',
            self::DeadEnd, self::StepLimit, self::Lost, self::Failed => 'danger',
        };
    }
}
