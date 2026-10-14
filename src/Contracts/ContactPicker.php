<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Contracts;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Models\Conversation;

/**
 * How an operator points a conversation at the right record by hand.
 *
 * `ContactDirectory` recognises somebody automatically and `ContactRegistrar`
 * creates somebody new; this is the third case, and the one that happens on a
 * busy desk: the thread is already linked, and linked to the wrong person.
 *
 * It happens for ordinary reasons. A phone number is recognised and the number
 * belongs to a husband, a colleague or the person who had it before. A customer
 * writes from their partner's account. Somebody filed the thread in a hurry.
 * Until an operator can re-point it, the only ways out are both bad: answer a
 * customer under somebody else's history, or lose the link and with it the
 * history that made the thread worth opening.
 *
 * **Searching is the host's job, and so is what a match means.** This package
 * cannot search a table it has never heard of, and must not try: an installation
 * whose contacts are employees searches by staff number, a clinic by patient
 * card, a travel agency by phone. The host returns labels an operator will
 * recognise, and gets back exactly the value it handed out.
 *
 * Optional, like the other two: an installation that does not bind it keeps the
 * desk it has, with no button and no way to re-point a thread by hand.
 */
interface ContactPicker
{
    /**
     * Records matching what the operator typed, as value => label.
     *
     * The value travels back to `attach()` untouched, so it may be anything the
     * host can resolve — an id, a uuid, a composite key. The label is what the
     * operator chooses from and should carry enough to tell two people with the
     * same name apart; a phone number usually does it.
     *
     * Must not throw: it runs while somebody is typing.
     *
     * @return array<string, string>
     */
    public function search(string $term): array;

    /**
     * What one value is called, asked after the operator has chosen it.
     *
     * Separate from `search()` because the screen asks it with no search term
     * at all — to re-draw the choice already made — and a host that answered
     * that by searching for an empty string would offer its whole directory.
     */
    public function label(string $value): ?string;

    /**
     * Points the conversation at that record and hands it back.
     *
     * Returning null means the value no longer resolves — a record deleted
     * between the search and the choice — and the desk says so rather than
     * silently leaving the thread where it was.
     */
    public function attach(Conversation $conversation, string $value): ?Model;
}
