<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a conversation is still on the operator's desk.
 *
 * Closing is housekeeping, not an ending: the row keeps its whole history and
 * the next incoming message reopens it. A conversation that was archived for
 * good would force the next message from the same person into a new thread,
 * and the operator would answer someone they have already spoken to twice
 * without knowing it.
 */
enum ConversationStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'gray',
        };
    }
}
