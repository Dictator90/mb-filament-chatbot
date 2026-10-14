<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

/**
 * A messenger account is part of its bot, and is authorised as one.
 *
 * Deliberately not a subject of its own. The connection is edited on the bot's
 * own screen, in a tab beside its name, so a separate permission would produce
 * a screen somebody may open with a tab they may not save — and a host would
 * have to grant a second right to restore what the first one already implied.
 *
 * The decision that follows from this: whoever may edit a bot may read its
 * tokens. That is the same person who may read every transcript the bot ever
 * had, so the token is not the secret being protected here.
 */
class ChannelConnectionPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'bots';
    }
}
