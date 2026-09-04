<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Variables;

use MB\FilamentChatbot\Models\Conversation;

/**
 * A named group of values a message template may use.
 *
 * The package supplies what it owns — what the visitor just said, which channel
 * they came from, what the bot has collected. Everything about the host's own
 * records comes from sources the host registers, and that boundary is the whole
 * point: a package that reached into an order table would only ever fit the
 * application it was written for.
 *
 * Sources are a closed list per installation, not an open door to any column.
 * A template names a variable and gets a string; it cannot ask for a field
 * nobody approved. That is what keeps a bot from reading an internal note or a
 * payment total out loud because someone wrote the wrong word in a message.
 */
interface VariableSource
{
    /**
     * The prefix its variables live under, so that two sources cannot collide
     * over a name as ordinary as "name".
     */
    public function key(): string;

    public function label(): string;

    /**
     * Variable name to human label, for the editor's picker.
     *
     * @return array<string, string>
     */
    public function fields(): array;

    /**
     * The value of one field for this conversation, or null when there is none.
     *
     * Null and an empty string mean different things and both matter: a template
     * renders null as a gap, while a scenario asking "is it filled" needs to
     * tell "we never found out" from "they answered with nothing".
     */
    public function value(Conversation $conversation, string $field): ?string;

    /**
     * Step kinds after which these variables become available, if the source
     * only makes sense once something has happened.
     *
     * Empty means always. A source that only fills in after a record has been
     * chosen says so here, and the editor stops offering its fields to nodes
     * placed above that choice — where they would render as empty gaps in a
     * sentence the visitor still receives.
     *
     * @return list<string>
     */
    public function availableAfter(): array;
}
