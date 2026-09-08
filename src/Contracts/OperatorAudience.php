<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Contracts;

use MB\FilamentChatbot\Models\Conversation;

/**
 * Who should be told that a conversation is waiting for a person.
 *
 * The package answers this itself out of the box — the assignee, or everybody
 * who may open the desk (`app/Chat/ChatDesk.php` in this package). That default
 * is deliberately opinionated rather than absent, because the thread that most
 * needs a bell is the unassigned one, and an installation on its first day has
 * bound nothing.
 *
 * This contract exists for the hosts whose answer is narrower than a permission:
 * a rota, a duty shift, a team that owns a channel, an escalation ladder. None
 * of those can be guessed from the conversation, and every one of them is
 * ordinary in a support desk of any size.
 *
 * **Never throws, and never rings on a rehearsal.** On the other side of this
 * is a customer's message being stored; a lookup that fell over would roll it
 * back, and a scenario tried out in the panel must not ring the office to say
 * that the bot would have called somebody.
 */
interface OperatorAudience
{
    /**
     * The notifiables to tell, or nothing at all.
     *
     * Anything Laravel can notify — a model with `Notifiable`, a collection of
     * them, a lazy generator. Returning an empty list is an ordinary answer and
     * not a failure: most of what a bot handles never needs a person.
     *
     * @return iterable<object>
     */
    public function for(Conversation $conversation): iterable;
}
