<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How many ways out a kind of step has, and where the editor may draw them.
 *
 * This is what the canvas needs in order to render a node it knows nothing else
 * about: a host registers its own step kind, declares its shape here, and the
 * editor draws the right handles without the package having to recognise the
 * kind at all.
 */
enum StepOutputs: string implements HasLabel
{
    /** A terminal step: hands over, closes, or otherwise stops the walk. */
    case None = 'none';

    /** One way out, stored in next_key. */
    case Single = 'single';

    /** One way out per authored option, and nothing else. */
    case Options = 'options';

    /** One way out per authored option, plus a fallback for everything else. */
    case OptionsElse = 'options_else';

    /**
     * Exactly two ways out, both named by the engine.
     *
     * Not a special case of OptionsElse, however similar the pictures look.
     * An option is something a person creates and labels themselves, and the
     * count is theirs to choose. A pair's two exits mean "it worked" and "it
     * did not" — outcomes the engine decides and the author only routes: a
     * lookup found rows or found none, the model answered or declined, the
     * approval came back yes or no.
     *
     * Let a pair be edited as options and the third click produces a node with
     * four branches, three of which the engine has no way to reach. The step
     * runs, one arrow is never taken, and the author spends the afternoon
     * asking why. Keeping the kinds apart is what makes that click impossible
     * rather than merely discouraged.
     */
    case Pair = 'pair';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.step_outputs.'.$this->value);
    }

    /**
     * Whether the author may add, remove and label the exits themselves.
     *
     * Asked by the editor before it offers an "add option" button. The
     * distinction it enforces is the one described on Pair.
     */
    public function authoredByHand(): bool
    {
        return $this === self::Options || $this === self::OptionsElse;
    }
}
