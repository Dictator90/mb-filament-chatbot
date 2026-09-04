<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Fields;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * One setting of a step, described in PHP and drawn by the editor.
 *
 * This is what makes a host-written step a first-class citizen. The scenario
 * editor is a React canvas, and without a description like this every new step
 * type would need its own hand-written panel inside that bundle — which means a
 * host could not add a step at all without forking the package and rebuilding
 * its JavaScript. Steps declare their settings instead, and one generic renderer
 * draws all of them.
 *
 * Descriptors travel to the browser as plain arrays inside the editor's
 * configuration. Nothing here may hold a value that cannot survive json_encode:
 * option closures are resolved on the server before the page is rendered,
 * precisely so that the editor never has to call back for them.
 *
 * @implements Arrayable<string, mixed>
 */
final class Field implements Arrayable
{
    public const TEXT = 'text';

    public const TEXTAREA = 'textarea';

    public const NUMBER = 'number';

    public const SELECT = 'select';

    public const TOGGLE = 'toggle';

    public const LIST = 'list';

    public const KEY_VALUE = 'key_value';

    /** A picker over the variables known at this point in the scenario. */
    public const VARIABLE = 'variable';

    /** A picker over the scenarios of the same bot. */
    public const SCENARIO = 'scenario';

    /** A picker over the declared data resources. */
    public const DATA_RESOURCE = 'data_resource';

    /** A picker over the capabilities the host has registered. */
    public const CAPABILITY = 'capability';

    private string $label;

    private ?string $hint = null;

    private ?string $placeholder = null;

    private mixed $default = null;

    /** @var array<string|int, string>|Closure|null */
    private array|Closure|null $options = null;

    private bool $multiple = false;

    private bool $required = false;

    private ?int $min = null;

    private ?int $max = null;

    private ?int $rows = null;

    /** @var array{field: string, values: list<mixed>}|null */
    private ?array $visibleWhen = null;

    private function __construct(
        private readonly string $name,
        private readonly string $type,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A field needs a name to store its value under.');
        }

        $this->label = $name;
    }

    public static function text(string $name): self
    {
        return new self($name, self::TEXT);
    }

    public static function textarea(string $name): self
    {
        return new self($name, self::TEXTAREA);
    }

    public static function number(string $name): self
    {
        return new self($name, self::NUMBER);
    }

    public static function select(string $name): self
    {
        return new self($name, self::SELECT);
    }

    public static function toggle(string $name): self
    {
        return new self($name, self::TOGGLE);
    }

    public static function list(string $name): self
    {
        return new self($name, self::LIST);
    }

    public static function keyValue(string $name): self
    {
        return new self($name, self::KEY_VALUE);
    }

    public static function variable(string $name): self
    {
        return new self($name, self::VARIABLE);
    }

    public static function scenario(string $name): self
    {
        return new self($name, self::SCENARIO);
    }

    public static function dataResource(string $name): self
    {
        return new self($name, self::DATA_RESOURCE);
    }

    public static function capability(string $name): self
    {
        return new self($name, self::CAPABILITY);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * A sentence under the field explaining what it changes.
     *
     * Worth writing for anything whose effect is not visible on the canvas. The
     * person editing a scenario is usually not the person who installed the
     * package, and a bare label like "attempts" tells them nothing about what
     * happens on the third one.
     */
    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * @param  array<string|int, string>|Closure  $options
     */
    public function options(array|Closure $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function rows(int $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Shows the field only while another field of the same step holds one of
     * these values.
     *
     * Kept declarative rather than left to the step's own code because the
     * editor has to decide visibility while the person types, with no round
     * trip to the server. A step that hid its own fields would need the panel
     * to ask the server on every keystroke.
     */
    public function visibleWhen(string $field, mixed ...$values): self
    {
        $this->visibleWhen = ['field' => $field, 'values' => array_values($values)];

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * The descriptor as the editor receives it.
     *
     * Nulls are stripped so that the configuration the page carries stays small:
     * it holds every field of every registered step type, and a scenario with
     * thirty node kinds would otherwise ship several kilobytes of "hint": null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'hint' => $this->hint,
            'placeholder' => $this->placeholder,
            'default' => $this->default,
            'options' => $this->resolvedOptions(),
            'multiple' => $this->multiple ?: null,
            'required' => $this->required ?: null,
            'min' => $this->min,
            'max' => $this->max,
            'rows' => $this->rows,
            'visible_when' => $this->visibleWhen,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string|int, string>|null
     */
    private function resolvedOptions(): ?array
    {
        if ($this->options === null) {
            return null;
        }

        return $this->options instanceof Closure
            ? ($this->options)()
            : $this->options;
    }
}
