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
     * `ios` is not a radius but an arrangement — tight grouping and a tail on
     * the last bubble of a run — so it is a named style rather than a number
     * somebody could arrive at by turning a corner dial up.
     */
    public const STYLES = ['rounded', 'ios', 'square'];

    public const LAUNCHERS = ['pill', 'circle'];

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
        return [
            'bubbles' => $this->values['bubbles'],
            'launcher' => $this->values['launcher'],
            'position' => $this->values['position'],
            'avatar' => $this->values['avatar'],
            'avatar_label' => $this->values['avatar_label'],
        ];
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

        $clean['bubbles'] = self::oneOf($values['bubbles'] ?? null, self::STYLES);
        $clean['launcher'] = self::oneOf($values['launcher'] ?? null, self::LAUNCHERS);
        $clean['position'] = self::oneOf($values['position'] ?? null, self::POSITIONS);
        $clean['avatar'] = self::avatar($values['avatar'] ?? null);
        $clean['avatar_label'] = self::label($values['avatar_label'] ?? null);

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

    private static function label(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : mb_substr($value, 0, 2);
    }
}
