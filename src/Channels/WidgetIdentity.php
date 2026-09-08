<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * Proof that the person in the widget is who the page says they are.
 *
 * The widget lives on a page this application does not control, and everything
 * it sends comes out of a stranger's browser. Take "I am signed in as Petrova,
 * my number is such-and-such" at its word and any visitor declares themselves
 * somebody else's customer — is greeted by that name, filed against that record
 * by a contact directory, and then reads that customer's orders through every
 * resource scoped on the conversation's contact. So the host signs what it
 * asserts, and an unverified signature means anonymous rather than an error.
 *
 * **The signature covers every field, not just the id, and that is the whole
 * point of this class existing.** Signing the id alone looks equivalent and is
 * not: a visitor can read their own valid pair straight out of their own
 * network tab, put a stranger's phone number beside it, and the pair still
 * verifies. The number is then recorded as proved — obtained legitimately, one
 * signature opened every account whose phone number its holder could guess.
 * This was the shape of the check until an installation with a real customer
 * directory behind it made the consequence obvious.
 *
 * Fields are joined by a newline and in a fixed order. Concatenated without a
 * separator, "Ivan" with phone "709…" signs identically to "Ivan7" with phone
 * "09…", and the separator has to be a character that cannot appear in a name.
 *
 * `sign()` is public because the host's own site computes the same value. Two
 * independent descriptions of one signature in two applications is the reliable
 * way to get a mismatch on the first field somebody adds.
 *
 * No secret means the feature is off and everybody is anonymous. That is the
 * only safe default: a check switched on without a secret admits everything.
 */
final class WidgetIdentity
{
    /**
     * The fields that are signed, in the order they are signed in.
     *
     * A constant rather than a literal at each end of the comparison: the order
     * is part of the contract with the host's site, and two spellings of it
     * verify nothing while looking exactly like a wrong secret.
     *
     * @var list<string>
     */
    public const SIGNED = ['id', 'name', 'phone', 'email'];

    /**
     * The verified fields of a signed visitor, or null for anybody else.
     *
     * Null covers a missing secret, a missing signature, one that does not
     * match, and a signature over nothing at all — signed emptiness says
     * nothing about a person and must not mark them as known.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array{id: string, name: ?string, phone: ?string, email: ?string}|null
     */
    public static function verify(?array $identity): ?array
    {
        $secret = trim((string) config('chatbot.widget.identity_secret', ''));

        if ($secret === '' || $identity === null) {
            return null;
        }

        $claimed = self::fields($identity);
        $signature = trim((string) ($identity['signature'] ?? ''));

        if ($claimed['id'] === '' || $signature === '') {
            return null;
        }

        /*
         * `hash_equals` and not `===`. Ordinary string comparison stops at the
         * first byte that differs, and the time it takes to answer is enough to
         * guess a signature one character at a time.
         */
        if (! hash_equals(self::sign($secret, $claimed), $signature)) {
            return null;
        }

        if ($claimed['name'] === '' && $claimed['phone'] === '' && $claimed['email'] === '') {
            return null;
        }

        return [
            'id' => mb_substr($claimed['id'], 0, 191),
            'name' => $claimed['name'] === '' ? null : mb_substr($claimed['name'], 0, 191),
            'phone' => $claimed['phone'] === '' ? null : mb_substr($claimed['phone'], 0, 32),
            'email' => $claimed['email'] === '' ? null : mb_substr($claimed['email'], 0, 191),
        ];
    }

    /**
     * The signature of one set of values.
     *
     * **The phone is signed exactly as it is written and compared exactly as it
     * is written.** Normalising it before the comparison is the obvious tidy-up
     * and it breaks the feature silently: the host's site signed
     * "+7 949 415-74-04", this would hash something else, the signature would
     * stop matching, and a signed-in visitor would quietly become anonymous.
     * Normalising happens later, when the number is being stored.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function sign(string $secret, array $identity): string
    {
        $fields = self::fields($identity);

        return hash_hmac(
            'sha256',
            implode("\n", array_map(static fn (string $key): string => $fields[$key], self::SIGNED)),
            $secret,
        );
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, string>
     */
    private static function fields(array $identity): array
    {
        $fields = [];

        foreach (self::SIGNED as $key) {
            $fields[$key] = trim((string) ($identity[$key] ?? ''));
        }

        return $fields;
    }
}
