<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * Why a model did not answer.
 *
 * Six reasons and one branch. Nothing in the package routes on which of these
 * came back — a scenario's "could not" exit is taken by all of them alike —
 * and that is the whole design: an unset key, an uninstalled library, a
 * provider outage, a refusal, an unreadable answer and an exhausted budget are
 * indistinguishable to the visitor, who is simply not getting an answer from a
 * model right now. Give them separate exits and every scenario in the
 * installation grows six arrows that lead to the same apology.
 *
 * They are told apart in the run journal instead, because that is where the
 * difference matters: "nobody configured a key" is fixed in settings and
 * "the provider refused" is fixed with the provider, and a single "failed"
 * would send somebody looking in the wrong place.
 */
enum AiFailure: string
{
    /** No driver, no key, no address — the state the package ships in. */
    case NotConfigured = 'not_configured';

    /** Configured, but nothing answered: network down, host unreachable. */
    case Unavailable = 'unavailable';

    /** The provider answered and declined — content filter, quota, bad request. */
    case Refused = 'refused';

    /** An answer came back that could not be read as what was asked for. */
    case Unparseable = 'unparseable';

    /** The installation's own spending limit stopped the call before it left. */
    case BudgetExhausted = 'budget_exhausted';

    /** The provider took longer than the driver was willing to wait. */
    case Timeout = 'timeout';

    /**
     * The default sentence for the run journal, used when a driver has nothing
     * more specific to say.
     */
    public function reason(): string
    {
        return match ($this) {
            self::NotConfigured => 'No model is configured for this installation.',
            self::Unavailable => 'The model provider could not be reached.',
            self::Refused => 'The model provider refused the request.',
            self::Unparseable => 'The model answered with something that could not be read.',
            self::BudgetExhausted => 'The budget for model calls is used up.',
            self::Timeout => 'The model provider did not answer in time.',
        };
    }
}
