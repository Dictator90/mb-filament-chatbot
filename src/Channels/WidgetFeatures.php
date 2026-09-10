<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Widget;

/**
 * What one placement of a bot lets a visitor do.
 *
 * A closed vocabulary for the same reason {@see WidgetTheme} is one: the answers
 * are sent to a script running on somebody else's page and are read there
 * without further checking, so anything unrecognised is dropped rather than
 * passed through. The difference is what they decide — the theme decides how the
 * widget looks, this decides what it accepts — and that is why they are stored
 * apart.
 *
 * **Attachments are off unless somebody switched them on, emoji are on unless
 * somebody switched them off.** Neither default is symmetry: an emoji is a
 * character the visitor could already have typed, while an attachment is this
 * application taking a file from an anonymous stranger and keeping it. The
 * second is a decision an installation makes on purpose, and a widget created
 * before this setting existed must not quietly start doing it.
 *
 * Delivery ticks follow emoji rather than attachments, and on purpose: they tell
 * the visitor nothing about the installation, only how far their own message has
 * got — which is the one conversation they are entitled to know everything
 * about. The switch exists because a desk that answers in a day would rather not
 * advertise the exact minute somebody opened the thread and said nothing.
 */
final class WidgetFeatures
{
    /**
     * @var array<string, bool>
     */
    private const DEFAULTS = [
        'emoji' => true,
        'attachments' => false,
        'receipts' => true,
        'replies' => false,
    ];

    /**
     * @param  array<string, bool>  $values
     */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string, mixed>|null  $values
     */
    public static function make(?array $values = null): self
    {
        $clean = [];

        foreach (self::DEFAULTS as $name => $default) {
            $clean[$name] = array_key_exists($name, (array) $values)
                ? filter_var($values[$name], FILTER_VALIDATE_BOOL)
                : $default;
        }

        return new self($clean);
    }

    public static function forWidget(?Widget $widget): self
    {
        return self::make($widget === null ? [] : (array) $widget->features);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public function emoji(): bool
    {
        return $this->values['emoji'];
    }

    public function attachments(): bool
    {
        return $this->values['attachments'];
    }

    /**
     * Whether the visitor sees how far their own messages have got.
     */
    public function receipts(): bool
    {
        return $this->values['receipts'];
    }

    /**
     * Whether the visitor may answer one particular message rather than the
     * conversation.
     *
     * Off unless switched on, like attachments and for a related reason: it
     * changes what arrives at the desk. A quote is only worth offering where
     * somebody reads the thread — on a desk with one operator and short threads
     * it is a button that adds a line to every message and answers nothing —
     * and where the channel behind the widget can carry it. A widget that
     * offered it and dropped it would have the visitor pointing at a message
     * nobody at the other end can see them pointing at.
     */
    public function replies(): bool
    {
        return $this->values['replies'];
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
