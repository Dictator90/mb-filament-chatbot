<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

/**
 * An attachment is answered for by the conversation it is in.
 *
 * Deliberately the same subject as `ConversationPolicy` rather than one of its
 * own. A file in a thread is part of that thread: an installation that lets
 * support staff read conversations and not scenarios has already said what it
 * thinks about the photograph somebody attached to one, and a second subject
 * here would be a second gate for the same secret — one that every host would
 * have to discover and grant separately, and that would default to open in
 * exactly the installations that had tightened the first.
 */
class AttachmentPolicy extends ChatbotPolicy
{
    protected function subject(): string
    {
        return 'conversations';
    }
}
