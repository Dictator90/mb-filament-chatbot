<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Capabilities;

use Closure;
use InvalidArgumentException;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Enums\SideEffect;

/**
 * One thing the host application can do, described so a scenario may ask for it.
 *
 * Data resources cover reading without writing code; this covers everything
 * else — award the points, cancel the booking, open the ticket, work out the
 * price. The host writes the closure once in a service provider, and from then
 * on the action is a node on the canvas that an author picks from a list.
 *
 * **The input is described with the same {@see Field} descriptors a node's own
 * settings use.** That is what keeps a host action from being a second-class
 * one. The editor already draws every kind of field from those descriptors, so
 * an action declaring `Field::number('points')->required()` gets the same
 * control, the same required marker and the same validation as a built-in node
 * — with no panel written in the editor's JavaScript, which a host cannot add
 * to without forking the package. Describe the input any other way and every
 * new action would arrive as a key-value box with a free-text value, and the
 * author would learn the field names from documentation instead of the screen.
 *
 * **There is deliberately no hash of the handler's source here.** A well-known
 * commercial builder pins the code of the handler into the released contract,
 * and the result is that renaming a local variable inside the method breaks a
 * live scenario: the hash moves, the pin no longer matches, and a conversation
 * that was working stops on a version mismatch nobody changed the meaning of.
 * Refactoring is not a release. What a scenario pins is `key@version`, and the
 * version is moved by the person who changed what the action *means* — a new
 * required input, a different unit, points that now cost money. That decision
 * cannot be derived from the bytes of a method, and pretending it can only
 * teaches people to bump a number they do not understand.
 */
final class CapabilityAction
{
    /**
     * @param  string  $key  stable and namespaced by the host — 'shop.award_points'
     * @param  string  $version  moved by hand when the meaning of the action changes
     * @param  list<Field>  $input  the descriptors the call form is drawn from
     * @param  Closure(array<string, mixed>, StepContext): array<string, mixed>  $handler
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $label,
        public readonly string $description,
        public readonly SideEffect $effect,
        public readonly array $input,
        public readonly bool $requiresApproval,
        public readonly Closure $handler,
        public readonly ?string $group = null,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A capability needs a key for a scenario to name it by.');
        }

        if (trim($version) === '') {
            throw new InvalidArgumentException("Capability [{$key}] needs a version, so a released scenario can pin it.");
        }
    }

    /**
     * The key and version as one string, which is what a released scenario pins.
     *
     * Built here rather than at each call site: the editor, the node's pins and
     * the release check all name the same thing, and three spellings of it is
     * how a release ends up comparing 'shop.award_points@1' against
     * 'shop.award_points:1' and reporting every action as changed.
     */
    public function reference(): string
    {
        return $this->key.'@'.$this->version;
    }

    /**
     * The description the editor receives.
     *
     * The handler is not in it, and cannot be: closures do not survive
     * json_encode, and an action's code has no business travelling to a browser.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'reference' => $this->reference(),
            'label' => $this->label,
            'description' => $this->description,
            'effect' => $this->effect->value,
            'requires_approval' => $this->requiresApproval,
            'group' => $this->group,
            'input' => array_map(static fn (Field $field): array => $field->toArray(), $this->input),
        ];
    }
}
