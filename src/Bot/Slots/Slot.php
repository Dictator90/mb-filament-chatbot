<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

use Closure;
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
 * The package ships only the slots any business has: a name, a way to ask
 * someone, what they want, how urgent it is. Everything else a host registers,
 * which is how a travel agency gets "destination" and a clinic gets "symptom"
 * without either of them appearing in the other's install.
 *
 * **Wording may be given as a closure, and the built-in slots give it that
 * way.** A slot is registered as a finished object in a provider's boot(), and
 * boot runs before any middleware that switches the locale for the request —
 * so a label translated at registration freezes whatever language the
 * configuration happened to name, and an installation that picks the visitor's
 * language per request would show the picker in one language and everything
 * beside it in another. Nothing about that looks broken from the code; it only
 * shows on screen, in one panel, to whoever changed the locale. Resolving on
 * read costs a call per lookup and cannot go stale.
 *
 * @implements Arrayable<string, mixed>
 */
final class Slot implements Arrayable
{
    /** @var Closure(): string|string */
    private Closure|string $label;

    /** @var Closure(): string|string|null */
    private Closure|string|null $question = null;

    /** @var Closure(): string|string|null */
    private Closure|string|null $hint = null;

    /** @var Closure(): array<string, string>|array<string, string> */
    private Closure|array $options = [];

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
     * @param  Closure(): array<string, string>|array<string, string>  $options
     */
    public static function choice(string $key, Closure|array $options): self
    {
        return (new self($key, SlotKind::Choice))->options($options);
    }

    /**
     * The human name of the slot, used in summaries an operator reads.
     *
     * @param  Closure(): string|string  $label
     */
    public function label(Closure|string $label): self
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
     *
     * @param  Closure(): string|string  $question
     */
    public function question(Closure|string $question): self
    {
        $this->question = $question;

        return $this;
    }

    /**
     * Shown to the author in the editor, never to the visitor.
     *
     * @param  Closure(): string|string  $hint
     */
    public function hint(Closure|string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * @param  Closure(): array<string, string>|array<string, string>  $options
     */
    public function options(Closure|array $options): self
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
        return (string) value($this->label);
    }

    public function defaultQuestion(): string
    {
        return (string) (value($this->question) ?? $this->name());
    }

    public function editorHint(): ?string
    {
        $hint = value($this->hint);

        return $hint === null ? null : (string) $hint;
    }

    /**
     * @return array<string, string>
     */
    public function choices(): array
    {
        return (array) value($this->options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $options = $this->choices();

        return array_filter([
            'value' => $this->key,
            'label' => $this->name(),
            'kind' => $this->kind->value,
            'question' => value($this->question) === null ? null : $this->defaultQuestion(),
            'hint' => $this->editorHint(),
            'options' => $options ?: null,
            'group' => $this->group,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
