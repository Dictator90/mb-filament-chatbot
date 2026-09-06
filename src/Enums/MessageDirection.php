<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which way a message travelled.
 *
 * Stored short ('in' / 'out') because the column is in every chat query's where
 * clause and in the unread subquery; the value is never shown to anyone, so
 * spelling it out would only widen an index.
 */
enum MessageDirection: string implements HasLabel
{
    case Incoming = 'in';
    case Outgoing = 'out';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.message_direction.'.$this->value);
    }
}
