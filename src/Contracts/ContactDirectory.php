<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Contracts;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Chat\ContactCard;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Who the person in a conversation is — answered by the host, never guessed
 * here.
 *
 * A conversation carries a nullable morph, and this package writes it, stores it
 * and hands it back without ever looking inside. That rule is what lets the same
 * package serve a travel agency whose contacts are clients, a helpdesk whose
 * contacts are employees and an installation with no contacts at all. The price
 * of the rule is that the package cannot say a single useful thing about the
 * person on its own, and this contract is where the host pays it.
 *
 * Two questions, and they are separate because they are asked at opposite ends
 * of a conversation. **Identify** runs when a message arrives and nobody knows
 * who sent it; **describe** runs when an operator opens the thread and needs to
 * know who they are answering. An installation may usefully answer one and not
 * the other.
 *
 * **Neither may throw.** On the other side of `identify()` is a customer's
 * message that has to be stored whatever the CRM thinks, and on the other side
 * of `describe()` is a desk full of threads that has to render. A directory that
 * threw would turn a slow lookup into a lost message and a missing card into a
 * blank screen. The package guards against it anyway, because a contract is a
 * request and a `try` is a guarantee — but a host that returns null on doubt
 * gets a better answer than one that lets an exception out.
 */
interface ContactDirectory
{
    /**
     * The record this message is from, when the application recognises it.
     *
     * Given both the conversation and the message, because recognition comes
     * from different places at different moments: a Telegram profile arrives on
     * the message, a phone number the visitor typed three turns ago is on the
     * conversation, and a signed identity from the host's own site may be on
     * either.
     *
     * **Asked only while the conversation is unlinked.** A thread already
     * pointing at somebody was linked by a signed identity, by an operator or by
     * a scenario, and all three know more than a lookup on a typed phone number.
     * Letting this overrule them would file a thread under the wrong customer on
     * the strength of a digit somebody mistyped.
     *
     * The package reads exactly two things off what comes back — its morph class
     * and its key — and nothing else, ever. Returning a model is not an
     * invitation to read its columns; the desk gets its wording from
     * {@see self::describe()}, where the host decides what may be shown.
     *
     * Null means "not recognised", which is the ordinary answer and not a
     * failure: most people who open a chat on a public page are strangers.
     */
    public function identify(Conversation $conversation, InboundMessage $message): ?Model;

    /**
     * The one line an operator needs about whoever this thread belongs to.
     *
     * A card rather than a model, so that what appears on the desk is the host's
     * decision rather than whatever the record happens to hold. The thread of a
     * customer with an unpaid balance and a passport on file should say what the
     * agency wants said about them — and nothing it does not.
     *
     * Null for an unlinked conversation, for a link whose record has since been
     * deleted, and for an installation that has nothing to add. All three mean
     * the same thing to the screen: show what the conversation itself knows.
     */
    public function describe(Conversation $conversation): ?ContactCard;
}
