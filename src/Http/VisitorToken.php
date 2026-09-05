<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * How the widget proves it is the same browser as last time.
 *
 * **A token, not a cookie.** The widget runs on somebody else's domain, so a
 * cookie set here would be a third-party cookie: blocked outright by Safari and
 * Firefox, being removed by Chrome, and requiring SameSite=None on top. A
 * conversation that survives a reload for some visitors and not others is worse
 * than one that never survives, because nobody can reproduce it. The token lives
 * in the embedding page's own localStorage and travels in a header.
 *
 * **Only the sha256 of it is stored.** The column is looked up on every widget
 * request, so it is an index over live credentials: a leaked backup, a query
 * accidentally logged, a screenshot of a database client would each hand the
 * reader everything needed to resume other people's conversations. Hashed, the
 * same leak is worthless — and nothing is lost, because the server never needs
 * to send the token back to anyone who does not already have it.
 */
final class VisitorToken
{
    /**
     * The header the widget sends. Not a query parameter: URLs end up in access
     * logs, referrer headers and browser history, and this is a credential.
     */
    public const HEADER = 'X-Chatbot-Token';

    /**
     * 64 characters from Laravel's CSPRNG. Long enough that guessing one is not
     * a strategy, which matters because guessing right means reading a stranger's
     * conversation.
     */
    public static function issue(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The raw token this request carried, or null when it carried none.
     *
     * A missing token is an ordinary state, not an error: every visitor starts
     * without one, and the first message they send is what earns them one.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = trim((string) $request->headers->get(self::HEADER, ''));

        return $token === '' ? null : $token;
    }
}
