<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Registry;

use InvalidArgumentException;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Variables\VariableSource;

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
