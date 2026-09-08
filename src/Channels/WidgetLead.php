<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Widget;

/**
 * What one placement asks for before the conversation starts.
 *
 * A name and a way to reach somebody, collected once at the top of the chat, so
 * that a thread abandoned at midnight is still a person the desk can call back.
 * Without it the widget produces threads that read "is anyone there?" and end,
 * with nothing on them but a browser token.
 *
 * **Off by default, and the default is the important part.** A form in front of
 * a chat box costs conversations — a proportion of the people who would have
 * asked a question simply do not — and that trade is the installation's to make,
 * not the package's. It is also the setting most likely to be turned on without
 * thinking about what it does to a scenario, for the reason below.
 *
 * **What it collects is a claim and never an identity.** Somebody typed a name
 * and a phone number into a box on a public page; that is worth exactly as much
 * as it sounds. It fills the contact fields on the thread and it does not stamp
 * `identified_at`, which stays reserved for a channel that proved who this is
 * ({@see WidgetIdentity}).
 *
 * That distinction is not pedantry, and it was learned the expensive way in the
 * application this package grew out of: there, a lead form plus a host that
 * created a customer record from what it collected made every anonymous visitor
 * "a known customer" before the bot ever ran. The branch that offered to sign
 * them in became unreachable, and a first-time visitor was greeted with "good to
 * see you again" followed by an empty list of their orders. Nothing failed;
 * every piece was correct on its own. Keep the two apart here — a claim fills a
 * field, proof stamps a column — and ask `identified` rather than "do we have a
 * phone number" wherever the answer matters.
 */
final class WidgetLead
{
    /**
     * The fields a placement may ask for, in the order they are drawn.
     *
     * A closed list for the same reason the theme is one: these are sent to a
     * script on somebody else's page, and each maps to a column the router
     * already knows how to merge. An arbitrary set of questions is a `collect`
     * node in a scenario, where the author can see it on the canvas.
     *
     * @var list<string>
     */
    public const FIELDS = ['name', 'phone', 'email'];

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $required
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $fields,
        public readonly array $required,
    ) {}

    /**
     * @param  array<string, mixed>|null  $values
     */
    public static function make(?array $values = null): self
    {
        $values = (array) $values;

        $fields = self::clean($values['fields'] ?? []);
        $required = array_values(array_intersect(self::clean($values['required'] ?? []), $fields));

        /*
         * A form switched on with no fields is not a form. Answering "yes" to
         * it would give the visitor a panel with a submit button and nothing
         * above it — and no way to type the message they came to type.
         */
        return new self(
            enabled: (bool) filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOL) && $fields !== [],
            fields: $fields,
            required: $required,
        );
    }

    public static function forWidget(?Widget $widget): self
    {
        return self::make($widget?->lead);
    }

    /**
     * What the visitor typed, reduced to the fields this placement asked for.
     *
     * The allow-list is applied on the way in as well as on the way out, for the
     * same reason the declared cookies are checked twice: what the widget was
     * told to ask is a hint to a script on a page this application does not
     * control, and what arrives is whatever the caller put in the field.
     *
     * @param  array<string, mixed>|null  $sent
     * @return array<string, string>
     */
    public function accept(?array $sent): array
    {
        if (! $this->enabled) {
            return [];
        }

        $kept = [];

        foreach ($this->fields as $field) {
            $value = $sent[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $kept[$field] = mb_substr(trim($value), 0, 191);
            }
        }

        return $kept;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'fields' => $this->fields,
            'required' => $this->required,
        ];
    }

    /**
     * @return list<string>
     */
    private static function clean(mixed $values): array
    {
        $clean = [];

        foreach ((array) $values as $value) {
            $value = is_string($value) ? trim($value) : '';

            if (in_array($value, self::FIELDS, true)) {
                $clean[$value] = $value;
            }
        }

        return array_values(array_intersect(self::FIELDS, $clean));
    }
}
