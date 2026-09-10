<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Widget;

/**
 * How a bot's widget looks, as a closed vocabulary.
 *
 * Everything here ends up inside a `style` attribute on somebody else's page,
 * which is the whole reason this class exists rather than a plain array. A
 * colour is checked to be a colour and a number is clamped to a range that makes
 * sense; anything unrecognised is dropped and replaced by the default. Passing a
 * stored string through to a custom property unchecked would let whoever can
 * edit a bot write CSS into every page the widget is embedded on — a panel
 * permission quietly becoming a permission on the customer's own site.
 *
 * The vocabulary is deliberately small and closed. A theme that accepted
 * arbitrary CSS would be impossible to preview honestly, impossible to keep
 * working across a redesign of the widget, and impossible to reason about when
 * somebody asks why a launcher is invisible on one site and fine on another.
 *
 * Stored in the bot's own `settings` bag rather than in new columns: it is one
 * object read and written as a whole, and a dozen columns would be a dozen
 * migrations the first time the widget grows a corner.
 */
final class WidgetTheme
{
    /**
     * The bubble shapes on offer.
     *
     * Only `rounded` and `square` are a matter of corner radius. `grouped` and
     * `ios` are arrangements — consecutive messages from one speaker pulled into
     * a block with their inner corners cut — which is why they are named styles
     * rather than numbers somebody could arrive at by turning a corner dial up.
     *
     * `grouped` and `ios` differ in one thing: whether the last bubble of a run
     * grows a tail. Both are offered because the grouping is what does the work
     * — it is what shows where one turn ends — and a tail on top of it is a
     * matter of taste that a shop matching its own design should get to settle.
     */
    public const STYLES = ['rounded', 'grouped', 'ios', 'square'];

    public const LAUNCHERS = ['pill', 'circle'];

    /**
     * The two shapes the message box comes in.
     *
     * `row` is one line: the two icons, the box and a button reading "Send",
     * side by side. `card` stacks them — the box across the top of a panel of
     * its own, the icons under it on the left, and a round arrow on the right —
     * which is what a phone messenger looks like and what a visitor arriving
     * from one expects.
     *
     * A named pair rather than a set of dials, for the same reason the bubbles
     * are: the difference is an arrangement, and nobody arrives at "the button
     * is now a circle below the box" by turning a number up.
     */
    public const COMPOSERS = ['row', 'card'];

    public const POSITIONS = ['right', 'left'];

    /**
     * @var array<string, string>
     */
    private const COLOURS = [
        'accent' => '#4f46e5',
        'on_accent' => '#ffffff',
        'surface' => '#ffffff',
        'ink' => '#0f172a',
        'muted' => '#64748b',
        'line' => '#e2e8f0',
        'agent' => '#f1f5f9',
        'agent_ink' => '#0f172a',
    ];

    /**
     * @var array<string, array{0: int, 1: int, 2: int}> name => [min, max, default]
     */
    private const SIZES = [
        'radius' => [0, 28, 16],
        'bubble_radius' => [0, 24, 12],
        'width' => [280, 520, 360],
    ];

    /**
     * The three parts of the chrome an installation can switch on, all off.
     *
     * **Off is not caution, it is the only honest default for each of them.**
     * The status line claims somebody is there; the names over the bubbles claim
     * the widget knows who is speaking well enough to say so on every line; the
     * teaser puts a sentence on a page nobody asked to have one on. A package
     * release that switched any of the three on would add all of that to every
     * widget already embedded on somebody else's site, and the person who would
     * have to explain it is the one who installed the widget last year.
     *
     * Booleans rather than three more strings, and grouped rather than folded
     * into {@see self::SIZES}, because they decide whether an element exists at
     * all — the widget reads them out of `flags()` alongside the launcher shape
     * and never as a custom property.
     *
     * @var array<string, bool>
     */
    private const TOGGLES = [
        'status' => false,
        'authors' => false,
        'teaser' => false,
    ];

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string, mixed>|null  $values
     */
    public static function make(?array $values = null): self
    {
        return new self(self::sanitise((array) $values));
    }

    public static function forBot(Bot $bot): self
    {
        return self::make((array) (($bot->settings ?? [])['widget'] ?? []));
    }

    public static function forWidget(?Widget $widget): self
    {
        return self::make($widget === null ? [] : (array) $widget->theme);
    }

    /**
     * Every value filled in, with anything unset resolved to its default.
     *
     * What a form has to show. A stored theme holds only what somebody changed,
     * so a screen bound straight to it draws seven empty colour boxes and one
     * filled one — and an empty box does not read as "the default", it reads as
     * "nothing", which is a colour the widget will never be. Somebody looking at
     * it cannot tell what the panel background currently is, and the preview
     * beside it is showing them a colour the form claims is not set.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::make()->toArray();
    }

    /**
     * The stored values, resolved against the defaults.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function resolved(?array $stored): array
    {
        return self::make($stored)->toArray();
    }

    /**
     * The values a bot has, ready to be written back into its settings bag.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * The custom properties the widget sets on its root element.
     *
     * Named to match what `widget.css` already reads, so the stylesheet stays
     * the single description of how the widget looks and this only supplies
     * numbers to it.
     *
     * @return array<string, string>
     */
    public function properties(): array
    {
        $properties = [];

        foreach (array_keys(self::COLOURS) as $name) {
            $properties['--chatbot-'.str_replace('_', '-', $name)] = (string) $this->values[$name];
        }

        $properties['--chatbot-radius'] = $this->values['radius'].'px';
        $properties['--chatbot-bubble-radius'] = $this->values['bubble_radius'].'px';
        $properties['--chatbot-width'] = $this->values['width'].'px';

        return $properties;
    }

    /**
     * What the widget needs beyond the custom properties: the choices that
     * change markup rather than colour.
     *
     * @return array<string, mixed>
     */
    public function flags(): array
    {
        $flags = [
            'bubbles' => $this->values['bubbles'],
            'launcher' => $this->values['launcher'],
            'composer' => $this->values['composer'],
            'position' => $this->values['position'],
            'avatar' => $this->values['avatar'],
            /*
             * The initials are derived here and not stored, so that what is
             * kept stays the name somebody typed. Reduced on the way in, the
             * form would open showing «МЛ» in a box whose label asks for a
             * company name — and the next person to edit the widget would be
             * looking at the answer rather than at the question.
             */
            'avatar_label' => self::initials($this->values['avatar_label']),
        ];

        foreach (array_keys(self::TOGGLES) as $name) {
            $flags[$name] = $this->values[$name];
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function sanitise(array $values): array
    {
        $clean = [];

        foreach (self::COLOURS as $name => $default) {
            $clean[$name] = self::colour($values[$name] ?? null) ?? $default;
        }

        foreach (self::SIZES as $name => [$min, $max, $default]) {
            $clean[$name] = self::size($values[$name] ?? null, $min, $max, $default);
        }

        foreach (self::TOGGLES as $name => $default) {
            $clean[$name] = array_key_exists($name, $values)
                ? filter_var($values[$name], FILTER_VALIDATE_BOOL)
                : $default;
        }

        $clean['bubbles'] = self::oneOf($values['bubbles'] ?? null, self::STYLES);
        $clean['launcher'] = self::oneOf($values['launcher'] ?? null, self::LAUNCHERS);
        $clean['composer'] = self::oneOf($values['composer'] ?? null, self::COMPOSERS);
        $clean['position'] = self::oneOf($values['position'] ?? null, self::POSITIONS);
        $clean['avatar'] = self::avatar($values['avatar'] ?? null);
        $clean['avatar_label'] = mb_substr(trim((string) ($values['avatar_label'] ?? '')), 0, 191);

        return $clean;
    }

    /**
     * A colour, or null when it is not one.
     *
     * Hex only, and that is a deliberate narrowing rather than an oversight.
     * `var(--x)`, `url(...)` and `calc()` are all valid CSS in the place these
     * end up, and each is a way to reach out of the property it was written
     * into. Three or six digits covers what a colour picker produces.
     */
    private static function colour(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1 ? $value : null;
    }

    private static function size(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): string
    {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    /**
     * The bot's picture, as an absolute https address or nothing.
     *
     * Nothing is the ordinary answer, and then the widget draws the initials
     * instead. A relative path is refused rather than resolved: the widget runs
     * on the customer's domain, where a relative path points at the customer's
     * own server and quietly resolves to their 404 page.
     */
    private static function avatar(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || ! str_starts_with($value, 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) === false ? null : $value;
    }

    /**
     * The initials drawn where the picture would be, taken from a name.
     *
     * **First letters of words, not the first letters of the string.** Two
     * characters off the front give "Ac" for "Acme Corporation" and "Ми" for
     * "Мир на ладони" — a syllable, which reads as a word cut in half rather
     * than as initials, and every other avatar on the customer's screen takes
     * them from word boundaries.
     *
     * Short words are skipped so the second letter is a name and not a
     * preposition: "Мир на ладони" is МЛ, not МН. A one-word name keeps two
     * letters of that word, because a single letter in a circle is not initials
     * either — which also means a name already reduced to initials survives
     * being read a second time.
     */
    private static function initials(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $meaningful = array_values(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) > 2,
        )) ?: $words;

        if (count($meaningful) < 2) {
            return mb_strtoupper(mb_substr($meaningful[0] ?? $value, 0, 2));
        }

        return mb_strtoupper(mb_substr($meaningful[0], 0, 1).mb_substr($meaningful[1], 0, 1));
    }
}
