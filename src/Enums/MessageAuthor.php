<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Who produced a message, kept apart from the direction it travelled.
 *
 * The two look redundant and are not. Three of these authors write outgoing
 * messages — the bot, a human operator and the system itself — and telling them
 * apart is what the rest of the package is built on: the bot has to fall silent
 * once a human has answered, and it cannot tell that from the direction alone,
 * since its own replies are outgoing too. Reports need the same split, or every
 * automated greeting counts as work an operator did.
 *
 * System covers what nobody typed: a channel's delivery failure, a handoff
 * notice, a conversation closed by a schedule. Filing those under Bot would put
 * plumbing into the transcript of what the bot said to the customer.
 */
enum MessageAuthor: string implements HasColor, HasLabel
{
    case Visitor = 'visitor';
    case Bot = 'bot';
    case Operator = 'operator';
    case System = 'system';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.message_author.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Visitor => 'gray',
            self::Bot => 'info',
            self::Operator => 'primary',
            self::System => 'warning',
        };
    }

    /**
     * Whether a human being wrote this. The bot stops talking after one of
     * these appears, so the answer has to come from a single place — spread
     * across the engine and the UI, the two would drift and the bot would carry
     * on replying over an operator mid-sentence.
     */
    public function isHuman(): bool
    {
        return $this === self::Visitor || $this === self::Operator;
    }
}
