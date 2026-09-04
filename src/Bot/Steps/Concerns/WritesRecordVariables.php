<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Concerns;

use MB\FilamentChatbot\Bot\Runtime;

/**
 * Writes one record into the conversation's variables, always the same way.
 *
 * Two nodes put a record into variables — the lookup and the picker — and the
 * names they write under have to agree, because the message that reads them
 * back was written once and does not know which node filled them in. Copied
 * into both classes, the convention drifts on the first change: a scenario that
 * says "your booking {{booking_reference}}" keeps working after a lookup and
 * prints a gap after a pick, and nothing anywhere reports a fault.
 */
trait WritesRecordVariables
{
    /**
     * Spreads a record over one variable per field, plus the name itself.
     *
     * The alternative — one variable holding the whole record — is what makes a
     * reply unreadable. A message inserts a single value inside a sentence
     * ("your booking {{booking_ref}} departs on {{booking_date}}"), and a name
     * bound to "12, 2026-05-01, paid, Antalya" turns that sentence into a
     * printed database row. So each declared field gets its own name, prefixed
     * so that two lookups in one scenario cannot overwrite each other.
     *
     * The bare name is bound to the record's first field as well, and that is
     * for the author who wrote {{booking}} because it read naturally. Left
     * unwritten it would substitute to an empty string — a message with a hole
     * in it and no hint as to why — while the first field is the one the
     * resource declares as the record's own identity.
     *
     * @param  array<string, scalar|null>  $row
     */
    protected function rememberRecord(Runtime $runtime, string $into, array $row): void
    {
        $first = null;

        foreach ($row as $field => $value) {
            $text = $this->textOf($value);

            $runtime->store($into.'_'.$field, $text);

            $first ??= $text;
        }

        $runtime->store($into, $first ?? '');
    }

    /**
     * Renders a value the way a message would print it.
     *
     * Booleans are the reason this is not a plain cast: `(string) false` is an
     * empty string, so a record whose flag is off would read as a record whose
     * flag is missing, and the sentence built from it would be silently wrong
     * rather than visibly odd.
     */
    protected function textOf(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '' : (string) $value;
    }
}
