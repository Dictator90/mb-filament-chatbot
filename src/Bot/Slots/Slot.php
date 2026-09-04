<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * One thing a bot can ask for and remember.
 *
 * Slots exist so that "the visitor's phone number" means the same thing in
 * every scenario of an installation: the same question, the same parsing, the
 * same name in the stored answers. Free-form variables cannot give that — one
 * scenario writes "phone", another "tel", and the host code that reads them
 * silently finds nothing.
 *
 * The package ships only the slots any business has: a name, a way to reach
 * someone, what they want, how urgent it is. Everything else a host registers,
 * which is how a travel agency gets "destination" and a clinic gets "symptom"
 * without either of them appearing in the other's install.
 *
 * @implements Arrayable<string, mixed>
 */
final class Slot implements Arrayable
{
    private string $label;

    private ?string $question = null;

    private ?string $hint = null;

    /** @var array<string, string> */
    private array $options = [];

    private ?string $group = null;

    private function __construct(
        private readonly string $key,
        private readonly SlotKind $kind,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('A slot needs a key to be stored and read under.');
        }

        $this->label = $key;
    }

    public static function make(string $key, SlotKind $kind = SlotKind::Text): self
    {
        return new self($key, $kind);
    }

    public static function text(string $key): self
    {
        return new self($key, SlotKind::Text);
    }

    public static function longText(string $key): self
    {
        return new self($key, SlotKind::LongText);
    }

    public static function phone(string $key): self
    {
        return new self($key, SlotKind::Phone);
    }

    public static function email(string $key): self
    {
        return new self($key, SlotKind::Email);
    }

    public static function number(string $key): self
    {
        return new self($key, SlotKind::Number);
    }

    public static function money(string $key): self
    {
        return new self($key, SlotKind::Money);
    }

    public static function date(string $key): self
    {
        return new self($key, SlotKind::Date);
    }

    public static function dateRange(string $key): self
    {
        return new self($key, SlotKind::DateRange);
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function choice(string $key, array $options): self
    {
        return (new self($key, SlotKind::Choice))->options($options);
    }

    /** The human name of the slot, used in summaries an operator reads. */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The default wording of the question.
     *
     * A default, not a fixture: a scenario may override it, because the same
     * slot is asked differently after a complaint than after a greeting. Having
     * a default at all is what lets a form ask for five things without anyone
     * writing five questions first.
     */
    public function question(string $question): self
    {
        $this->question = $question;

        return $this;
    }

    /** Shown to the author in the editor, never to the visitor. */
    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * @param  array<string, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** Groups slots in the editor's picker — "contact", "request", "billing". */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function kind(): SlotKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->label;
    }

    public function defaultQuestion(): string
    {
        return $this->question ?? $this->label;
    }

    /**
     * @return array<string, string>
     */
    public function choices(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'value' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'question' => $this->question,
            'hint' => $this->hint,
            'options' => $this->options ?: null,
            'group' => $this->group,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
