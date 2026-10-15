<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Capabilities\CapabilityAction;

/**
 * One thing a model is allowed to ask for, described the way providers want it.
 *
 * A capability already describes itself well enough to be offered: it has a
 * key, a sentence saying what it does, and its input declared as the same
 * {@see Field} descriptors the editor draws a form from. So this is a
 * translation, not a second description — and keeping it a translation is the
 * point. A tool catalogue written by hand beside the capabilities would drift
 * from them silently, and the symptom is a model calling an action with the
 * field names from last release.
 *
 * **The name is not the key.** Providers accept `[a-zA-Z0-9_-]` in tool names,
 * and a host's keys are namespaced with dots — `crm.create_order`. The dots
 * become underscores here, and whoever offers the tool keeps the map back to
 * the capability: a name translated in two places is a tool the model can call
 * and the loop cannot find.
 */
final class AiTool
{
    /**
     * @param  array<string, mixed>  $schema  a JSON Schema object describing the input
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $schema,
    ) {}

    public static function fromCapability(CapabilityAction $action): self
    {
        return new self(
            name: self::nameFor($action->key),
            description: trim($action->description) === '' ? $action->label : $action->description,
            schema: self::schemaFor($action->input),
        );
    }

    /**
     * The tool name a capability key is offered under.
     *
     * Static and public because the loop needs the same answer to map a call
     * back to the action it names, and the two must agree exactly.
     */
    public static function nameFor(string $key): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($key));
    }

    /**
     * An object schema built from the descriptors the capability declared.
     *
     * Types are mapped rather than passed through: a provider validates the
     * model's arguments against this schema before the call even leaves it, so
     * a `number` field described as a string is a date arriving as "two" and a
     * refusal landing in the run journal instead of in the schema.
     *
     * A `select` carries its options as an enum. That is the one translation
     * that changes what the model can do rather than how it is told: without it
     * the model invents a status, the capability refuses the payload, and the
     * customer hears "I could not do that" about a word nobody offered.
     *
     * @param  list<Field>  $input
     * @return array<string, mixed>
     */
    private static function schemaFor(array $input): array
    {
        $properties = [];
        $required = [];

        foreach ($input as $field) {
            $descriptor = $field->toArray();
            $name = $field->name();

            $property = ['type' => self::typeFor((string) $field->type())];

            if (($descriptor['label'] ?? '') !== '' || ($descriptor['hint'] ?? '') !== '') {
                $property['description'] = trim(implode('. ', array_filter([
                    (string) ($descriptor['label'] ?? ''),
                    (string) ($descriptor['hint'] ?? ''),
                ])));
            }

            $options = array_keys((array) ($descriptor['options'] ?? []));

            if ($property['type'] === 'string' && $options !== []) {
                $property['enum'] = array_values(array_map(static fn (mixed $key): string => (string) $key, $options));
            }

            if ($property['type'] === 'array') {
                $property['items'] = ['type' => 'string'];
            }

            $properties[$name] = $property;

            if (($descriptor['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], static fn (mixed $value): bool => $value !== []);
    }

    private static function typeFor(string $fieldType): string
    {
        return match ($fieldType) {
            'number' => 'number',
            'toggle' => 'boolean',
            'list' => 'array',
            'key_value' => 'object',
            default => 'string',
        };
    }
}
