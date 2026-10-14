<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Contracts;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Models\Conversation;

/**
 * How a conversation becomes a record in the host's own directory.
 *
 * The other half of `ContactDirectory`, which can only recognise somebody who
 * is already there. A visitor who writes for the first time, gives a name and a
 * phone number and is handed to a person exists nowhere afterwards: the next
 * time they write, the bot greets them as a stranger and the operator retypes
 * what the transcript already says.
 *
 * **Optional on purpose.** An installation that does not bind this keeps the
 * old behaviour exactly — no button, no automatic creation — because deciding
 * that a chat visitor deserves a row in the customer directory is the host's
 * decision and sometimes the law's. A helpdesk whose contacts are employees has
 * nothing to create at all.
 *
 * **The threshold belongs to the host, not to the button.** The package asks
 * `canRegister()` and shows the button only when the answer is yes; what counts
 * as enough — a name the person gave themselves, a way of reaching them, a
 * setting the agency can switch off — is a question this package has no right
 * to answer.
 */
interface ContactRegistrar
{
    /**
     * Whether there is enough in this conversation to create a record from.
     *
     * Must not throw: it is asked while a desk full of threads is rendering.
     */
    public function canRegister(Conversation $conversation): bool;

    /**
     * Creates the record, links the conversation to it and hands it back.
     *
     * Returning null means "not created" rather than "failed" — a host that
     * refuses because the setting is off, or because the same person was found
     * and linked instead, has done the right thing and has nothing to report.
     */
    public function register(Conversation $conversation): ?Model;
}
