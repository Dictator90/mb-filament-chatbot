<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use MB\FilamentChatbot\Models\Conversation;

/**
 * Writes what a scenario learned about the person onto the conversation itself.
 *
 * A collected answer is a scenario variable: it lives for the run and is gone
 * when the run ends. That is right for "which order number", and wrong for the
 * three things the rest of the application asks about a conversation — who this
 * is, and how to reach them. Without this the desk goes on saying
 * "Conversation #5" to an operator who is reading the visitor's name three lines
 * below it.
 *
 * **Written only when the scenario says so.** A slot happening to be called
 * `name` does not rename a conversation: slots are named by whoever wrote the
 * scenario, `name` is the obvious word for a dozen unrelated questions, and a
 * package that renamed a thread on that basis would be guessing about the one
 * field an operator reads first.
 *
 * A filled field is never overwritten by a collected one. What is already there
 * came from the channel — a signed identity from the host, a Telegram profile —
 * and that is a fact about who the person is, where an answer typed into a chat
 * box is a claim. Somebody who types "Santa Claus" must not rename the signed-in
 * customer whose thread this is.
 */
final class ConversationFacts
{
    /**
     * Which conversation column each choice writes to.
     *
     * A closed list, and short on purpose. These are the fields the package
     * itself reads — `Conversation::title()` and the contact columns the desk
     * shows — and letting a scenario write anywhere on the row would make the
     * conversation a scratch pad that the next release cannot change.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'name' => 'display_name',
        'phone' => 'contact_phone',
        'email' => 'contact_email',
    ];

    /**
     * Remembers one value, if the choice is one this understands and the field
     * is not already filled from a better source.
     */
    public function remember(Conversation $conversation, string $choice, string $value): bool
    {
        $column = self::FIELDS[$choice] ?? null;
        $value = trim($value);

        if ($column === null || $value === '') {
            return false;
        }

        if (filled($conversation->{$column})) {
            return false;
        }

        $conversation->forceFill([$column => $value])->save();

        return true;
    }
}
