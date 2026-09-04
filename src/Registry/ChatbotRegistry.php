<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Registry;

use InvalidArgumentException;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Variables\VariableSource;
use MB\FilamentChatbot\Capabilities\CapabilityAction;

/**
 * Everything a host application may add to the chatbot.
 *
 * The registry exists so that the package ships no knowledge of any particular
 * business. A travel agency needs a "pick one of your bookings" node, a help
 * desk needs "escalate to the on-call engineer", and neither belongs in a
 * package that the other one installs. Both register their own instead.
 *
 * Registration happens in a host service provider's boot(). The registry is a
 * container singleton, so registrations accumulate across the host, its own
 * packages and the tests.
 *
 * Sections arrive with their subsystems: step types first, then capabilities,
 * data-resource scope sources, channel drivers and AI drivers.
 */
class ChatbotRegistry
{
    /**
     * Step types by their stable key.
     *
     * @var array<string, class-string<StepType>>
     */
    protected array $steps = [];

    /**
     * Resolved step instances.
     *
     * Step types are stateless and the same instance runs every node of its
     * kind, so resolving one per key per request is both safe and noticeably
     * cheaper: the editor asks every registered type to describe itself on
     * every page load, and a scenario walk asks again on every node.
     *
     * @var array<string, StepType>
     */
    protected array $resolved = [];

    /**
     * Adds a step type, replacing any earlier one with the same key.
     *
     * Replacement is allowed on purpose. Overriding a built-in node is the
     * cheapest way to change one detail of it — the wording it sends, the limit
     * it enforces — without forking the package, and forbidding it would only
     * push hosts into copying the whole class anyway.
     *
     * @param  class-string<StepType>  $step
     */
    public function registerStep(string $step): static
    {
        if (! is_subclass_of($step, StepType::class)) {
            throw new InvalidArgumentException(
                "[{$step}] must extend ".StepType::class.' to be registered as a step type.',
            );
        }

        $key = $step::key();

        if ($key === '') {
            throw new InvalidArgumentException("[{$step}] must return a non-empty key().");
        }

        $this->steps[$key] = $step;
        unset($this->resolved[$key]);

        return $this;
    }

    /**
     * @param  iterable<class-string<StepType>>  $steps
     */
    public function registerSteps(iterable $steps): static
    {
        foreach ($steps as $step) {
            $this->registerStep($step);
        }

        return $this;
    }

    /**
     * The step type behind a key, or null when nothing claims it.
     *
     * Null is an ordinary answer, not a failure. A scenario may well hold nodes
     * of a kind whose package has since been removed, and the editor has to be
     * able to open it: the node is drawn as a problem and the author decides
     * what to do. Throwing here would make one uninstalled package take the
     * whole scenario screen down with it.
     */
    public function step(string $key): ?StepType
    {
        if (! isset($this->steps[$key])) {
            return null;
        }

        return $this->resolved[$key] ??= app($this->steps[$key]);
    }

    /**
     * @return array<string, StepType>
     */
    public function steps(): array
    {
        $steps = [];

        foreach (array_keys($this->steps) as $key) {
            $steps[$key] = $this->step($key);
        }

        return $steps;
    }

    /**
     * @return array<string, class-string<StepType>>
     */
    public function stepClasses(): array
    {
        return $this->steps;
    }

    public function hasStep(string $key): bool
    {
        return isset($this->steps[$key]);
    }

    /**
     * Forgets a step type.
     *
     * Here for hosts that want a built-in node gone from the palette entirely —
     * an installation with no knowledge base has no use for the node that reads
     * from it, and an unusable node on the palette is a support ticket waiting
     * to happen.
     */
    public function forgetStep(string $key): static
    {
        unset($this->steps[$key], $this->resolved[$key]);

        return $this;
    }

    /**
     * Slots by key.
     *
     * @var array<string, Slot>
     */
    protected array $slots = [];

    /**
     * Condition fields by key.
     *
     * @var array<string, class-string<ConditionField>>
     */
    protected array $conditions = [];

    /** @var array<string, ConditionField> */
    protected array $resolvedConditions = [];

    /**
     * Variable sources by key.
     *
     * @var array<string, VariableSource>
     */
    protected array $variableSources = [];

    public function registerSlot(Slot $slot): static
    {
        $this->slots[$slot->key()] = $slot;

        return $this;
    }

    /**
     * @param  iterable<Slot>  $slots
     */
    public function registerSlots(iterable $slots): static
    {
        foreach ($slots as $slot) {
            $this->registerSlot($slot);
        }

        return $this;
    }

    public function slot(string $key): ?Slot
    {
        return $this->slots[$key] ?? null;
    }

    /**
     * @return array<string, Slot>
     */
    public function slots(): array
    {
        return $this->slots;
    }

    /**
     * @param  class-string<ConditionField>  $field
     */
    public function registerCondition(string $field): static
    {
        if (! is_subclass_of($field, ConditionField::class)) {
            throw new InvalidArgumentException(
                "[{$field}] must extend ".ConditionField::class.' to be registered as a condition.',
            );
        }

        $this->conditions[$field::key()] = $field;
        unset($this->resolvedConditions[$field::key()]);

        return $this;
    }

    /**
     * @param  iterable<class-string<ConditionField>>  $fields
     */
    public function registerConditions(iterable $fields): static
    {
        foreach ($fields as $field) {
            $this->registerCondition($field);
        }

        return $this;
    }

    /**
     * Null for an unknown key, for the same reason step() answers null: a saved
     * branch may name a condition whose package is gone, and the scenario still
     * has to open.
     */
    public function condition(string $key): ?ConditionField
    {
        if (! isset($this->conditions[$key])) {
            return null;
        }

        return $this->resolvedConditions[$key] ??= app($this->conditions[$key]);
    }

    /**
     * @return array<string, ConditionField>
     */
    public function conditions(): array
    {
        $fields = [];

        foreach (array_keys($this->conditions) as $key) {
            $fields[$key] = $this->condition($key);
        }

        return $fields;
    }

    /**
     * Host capabilities by key.
     *
     * @var array<string, CapabilityAction>
     */
    protected array $capabilities = [];

    /**
     * Adds a host action, leaving a key that is already claimed alone.
     *
     * **Kept in this class rather than in a registry of its own.** A separate
     * CapabilityRegistry would mean a second container singleton, a second
     * facade for hosts to learn and a second place the editor has to ask about
     * what a scenario may refer to — while being the same thing this class
     * already is: the list of what the host added. The registrations also
     * arrive together, in one provider, and splitting them would leave a host
     * wiring two objects to describe one application.
     *
     * The taken key is not overwritten, which is the opposite of what
     * registerStep() does above, and the difference is which way the damage
     * runs. A step type is a screen and a behaviour, and replacing one is the
     * documented way to reword a built-in node. An action changes the host's
     * own records: two packages claiming 'billing.refund' is not a customised
     * built-in, it is one of them quietly performing the other's refund, and
     * the only trace of it is money moving while the journal still reads
     * successful. Registration order is manifest order, so a silent overwrite
     * would land one way on the developer's machine and the other way in
     * production. Replacing an action stays possible and has to be said out
     * loud: forgetCapability() first, then register.
     */
    public function registerCapability(CapabilityAction $action): static
    {
        if (isset($this->capabilities[$action->key])) {
            return $this;
        }

        $this->capabilities[$action->key] = $action;

        return $this;
    }

    /**
     * @param  iterable<CapabilityAction>  $actions
     */
    public function registerCapabilities(iterable $actions): static
    {
        foreach ($actions as $action) {
            $this->registerCapability($action);
        }

        return $this;
    }

    /**
     * Null for an unknown key, for the same reason step() answers null: a saved
     * node may name an action whose package has since been removed, and both
     * the editor and a running conversation have to survive that — the first by
     * drawing a defect on the node, the second by taking its "could not" exit.
     */
    public function capability(string $key): ?CapabilityAction
    {
        return $this->capabilities[$key] ?? null;
    }

    /**
     * @return array<string, CapabilityAction>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $key): bool
    {
        return isset($this->capabilities[$key]);
    }

    /**
     * Drops an action, so that registering over one is a deliberate sentence
     * rather than an accident of boot order.
     */
    public function forgetCapability(string $key): static
    {
        unset($this->capabilities[$key]);

        return $this;
    }

    public function registerVariableSource(VariableSource $source): static
    {
        $this->variableSources[$source->key()] = $source;

        return $this;
    }

    public function variableSource(string $key): ?VariableSource
    {
        return $this->variableSources[$key] ?? null;
    }

    /**
     * @return array<string, VariableSource>
     */
    public function variableSources(): array
    {
        return $this->variableSources;
    }
}
