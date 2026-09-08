<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Registry;

use InvalidArgumentException;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Recipes\Recipe;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Variables\VariableSource;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Console\Doctor\DoctorCheck;

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

    /**
     * Channel drivers by their key.
     *
     * @var array<string, ChannelDriver>
     */
    protected array $channels = [];

    /**
     * Adds a channel, replacing any earlier one with the same key.
     *
     * **A section here rather than a ChannelRegistry of its own**, for the same
     * reason capabilities above are: a host wires its whole chatbot in one
     * provider, and a second singleton with a second facade would only split
     * "what this application added" across two objects that always travel
     * together. The panel already asks this class what an installation is made
     * of; the list of channels a conversation can arrive on is the same kind of
     * answer.
     *
     * Replacement is allowed, unlike a capability. A driver is a transport, not
     * an action on the host's records: swapping the Telegram driver for one that
     * routes through a company gateway is an ordinary deployment decision, and
     * the worst a wrong one can do is fail visibly on the message row. Boot
     * order still decides the winner, so the package's own drivers skip keys
     * that are already claimed.
     */
    public function registerChannel(ChannelDriver $driver): static
    {
        $key = $driver->key();

        if ($key === '') {
            throw new InvalidArgumentException(
                '['.$driver::class.'] must return a non-empty key() to be registered as a channel.',
            );
        }

        $this->channels[$key] = $driver;

        return $this;
    }

    /**
     * @param  iterable<ChannelDriver>  $drivers
     */
    public function registerChannels(iterable $drivers): static
    {
        foreach ($drivers as $driver) {
            $this->registerChannel($driver);
        }

        return $this;
    }

    /**
     * The driver behind a key, or null when nothing claims it.
     *
     * Null for the same reason step() answers null, but the consequence differs
     * and matters more: conversations survive the removal of the package that
     * brought their channel, and they still have to open and read. What must not
     * happen is a reply to such a thread disappearing without trace, which is
     * why the outbox turns this null into a failed message carrying the reason.
     */
    public function channel(string $key): ?ChannelDriver
    {
        return $this->channels[$key] ?? null;
    }

    /**
     * @return array<string, ChannelDriver>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function hasChannel(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    public function forgetChannel(string $key): static
    {
        unset($this->channels[$key]);

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

    /**
     * Installation checks the host has added, by key.
     *
     * @var array<string, class-string<DoctorCheck>>
     */
    protected array $doctorChecks = [];

    /**
     * Adds a check to `chatbot:doctor`.
     *
     * Worth having because half of what breaks a chat installation is outside
     * the package: a credential the host stores its own way, a queue worker on
     * a machine nobody restarted, an endpoint the host's own driver talks to.
     * A host that can add its check to the same command gets one answer to
     * "is this deployment healthy" instead of two.
     *
     * A check claiming a built-in key replaces it, matching how step types
     * behave: overriding one check of a package you cannot fork is a real need,
     * and the alternative is copying the whole command.
     *
     * @param  class-string<DoctorCheck>  $check
     */
    public function registerDoctorCheck(string $check): static
    {
        if (! is_subclass_of($check, DoctorCheck::class)) {
            throw new InvalidArgumentException(
                "[{$check}] must extend ".DoctorCheck::class.' to be registered as a doctor check.',
            );
        }

        $this->doctorChecks[$check::key()] = $check;

        return $this;
    }

    /**
     * @return array<string, class-string<DoctorCheck>>
     */
    public function doctorChecks(): array
    {
        return $this->doctorChecks;
    }

    /**
     * Scenarios worth starting from, by key.
     *
     * @var array<string, class-string<Recipe>>
     */
    protected array $recipes = [];

    /**
     * @var array<string, Recipe>
     */
    protected array $resolvedRecipes = [];

    /**
     * Adds a shape the canvas can offer as a starting point.
     *
     * A host's own recipes are the useful half of this. What the package ships
     * are three general shapes; what a company actually starts from is its own
     * refund conversation, its own booking check — written once by whoever knows
     * the wording, and offered to every colleague who opens a blank canvas
     * afterwards.
     *
     * A claimed key is left alone, like a capability and unlike a step type. A
     * recipe is a piece of somebody's copywriting, and two packages claiming one
     * key is not a customised built-in — it is one company's wording silently
     * replacing another's on the board.
     *
     * @param  class-string<Recipe>  $recipe
     */
    public function registerRecipe(string $recipe): static
    {
        if (! is_subclass_of($recipe, Recipe::class)) {
            throw new InvalidArgumentException(
                "[{$recipe}] must implement ".Recipe::class.' to be registered as a recipe.',
            );
        }

        $this->recipes[$recipe::key()] ??= $recipe;

        return $this;
    }

    /**
     * @param  iterable<class-string<Recipe>>  $recipes
     */
    public function registerRecipes(iterable $recipes): static
    {
        foreach ($recipes as $recipe) {
            $this->registerRecipe($recipe);
        }

        return $this;
    }

    public function recipe(string $key): ?Recipe
    {
        if (! isset($this->recipes[$key])) {
            return null;
        }

        return $this->resolvedRecipes[$key] ??= app($this->recipes[$key]);
    }

    /**
     * @return array<string, Recipe>
     */
    public function recipes(): array
    {
        $recipes = [];

        foreach (array_keys($this->recipes) as $key) {
            $recipes[$key] = $this->recipe($key);
        }

        return $recipes;
    }
}
