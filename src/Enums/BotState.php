<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What the bot is doing in one conversation.
 *
 * HandedOff is a separate state from Off rather than a flag beside it. Both
 * mean "the bot says nothing", but only one of them may be resumed: a
 * conversation the bot never ran is free to start a scenario on the next
 * message, while one a human took over must stay quiet until that human says
 * otherwise. Collapsed into a single "off", every handoff would be undone by
 * the visitor's next line.
 */
enum BotState: string implements HasColor, HasLabel
{
    case Off = 'off';
    case Active = 'active';
    case HandedOff = 'handed_off';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.bot_state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Off => 'gray',
            self::Active => 'success',
            self::HandedOff => 'warning',
        };
    }
}
