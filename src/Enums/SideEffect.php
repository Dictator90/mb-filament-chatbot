<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Whether an operation only looks at data or changes it.
 *
 * Kept as a first-class value rather than inferred from a method name, because
 * everything that has to be decided differently for the two — whether a retry is
 * safe, whether a dry run may execute it, whether a person confirms first —
 * is decided before the operation is known in any detail.
 */
enum SideEffect: string implements HasLabel
{
    case Read = 'read';

    case Write = 'write';

    public function getLabel(): string
    {
        return match ($this) {
            self::Read => 'Reads data',
            self::Write => 'Changes data',
        };
    }

    /**
     * A read may be repeated after a timeout; a write may not.
     */
    public function isRepeatable(): bool
    {
        return $this === self::Read;
    }
}
