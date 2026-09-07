<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http;

use MB\FilamentChatbot\Channels\WidgetTheme;

/**
 * The unsaved widget carried from the panel form to the preview frame.
 *
 * The preview has to show what is **on the screen right now**, not what is in
 * the database. Somebody drags the accent colour and looks at the frame; a
 * preview that read the stored row would answer with the colour they have just
 * replaced, which is worse than no preview at all — it looks like the change
 * did not take.
 *
 * So the draft travels in the URL rather than through storage. Two reasons, and
 * the second is the one that decided it. A cache or session entry written on
 * every keystroke is state with an owner, a lifetime and a cleanup nobody
 * writes; and, worse, it makes the frame's content depend on something the
 * frame's address does not name — so the iframe does not reload when the draft
 * changes, and the panel would have to poke it. With the draft in the address,
 * a changed field changes `src`, and the browser reloads the frame for free.
 *
 * Nothing here is a secret: colours, corner radii and the wording of a launcher
 * are on public display the moment the widget draws itself. What the URL must
 * not do is carry style straight onto a page, which is why the frame passes
 * every value through {@see WidgetTheme} before it reaches a custom property —
 * the same gate the saved widget goes through, so the preview cannot show
 * something the real widget would refuse.
 */
final class WidgetDraft
{
    /**
     * The address of the frame for one draft.
     *
     * The panel id is part of the path rather than assumed, because the guard
     * on the other end is a panel's guard. A host may run several panels on
     * different guards, and a preview that authenticated against whichever
     * happens to be the default would let a customer-facing panel's session
     * open a staff-only screen.
     *
     * @param  array<string, mixed>  $draft
     */
    public static function url(string $panelId, array $draft): string
    {
        return route('chatbot.widget.preview', [
            'panel' => $panelId,
            'draft' => self::encode($draft),
        ]);
    }

    /**
     * Base64url rather than plain JSON in a query string.
     *
     * The values include `#` in every colour, and a raw `#` in a URL starts the
     * fragment — everything after the first colour would never reach the server,
     * and the preview would silently show defaults for most of the theme.
     *
     * @param  array<string, mixed>  $draft
     */
    public static function encode(array $draft): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($draft)), '+/', '-_'), '=');
    }

    /**
     * Whatever came back, as an array — and an empty one for anything that is
     * not a draft.
     *
     * A malformed parameter answers with defaults rather than with an error: the
     * frame is a picture, and a 500 inside an iframe on a form is a black box
     * whose cause is nowhere on the screen it appears in.
     *
     * @return array<string, mixed>
     */
    public static function decode(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: false);

        if ($decoded === false) {
            return [];
        }

        $draft = json_decode($decoded, associative: true);

        return is_array($draft) ? $draft : [];
    }
}
