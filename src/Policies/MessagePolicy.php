<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

/**
 * A message is only ever reached through its conversation, so it answers to the
 * same subject.
 *
 * Given its own ability it would be one more thing to grant, and the first
 * installation to forget would show an operator a list of conversations they
 * could not open.
 */
class MessagePolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'conversations';
    }
}
