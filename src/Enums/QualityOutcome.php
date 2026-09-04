<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a quality test ended.
 *
 * Broken is the reason this is an enum rather than a boolean. A run that could
 * not happen at all — the scenario was deleted, the data resource it needs is
 * switched off, the channel is gone — is not a failing bot. Recording it as a
 * failure buries the cause under a red badge that invites somebody to go looking
 * for a bug in a conversation that never ran, and it makes a broken suite look
 * like a broken bot right up until the day both are ignored.
 */
enum QualityOutcome: string implements HasLabel
{
    case Passed = 'passed';

    /**
     * The bot ran and said something other than what was expected.
     */
    case Failed = 'failed';

    /**
     * The test could not be run. Fix the test or what it points at, not the bot.
     */
    case Broken = 'broken';

    public function getLabel(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Broken => 'Could not run',
        };
    }
}
