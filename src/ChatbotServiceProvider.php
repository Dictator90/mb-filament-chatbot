<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Conditions\Fields\ChannelCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\DateCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\DayCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\HandedOffCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\IdentifiedCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\MonthCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\OnlyGreetingCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\PageCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\ReturningCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\TimeCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\TurnsCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\VariableCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\WeekdayCondition;
use MB\FilamentChatbot\Bot\Conditions\Fields\WorkingHoursCondition;
use MB\FilamentChatbot\Bot\Graph\GraphConfig;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Bot\Slots\BuiltInSlots;
use MB\FilamentChatbot\Bot\Steps\Types\ActionStep;
use MB\FilamentChatbot\Bot\Steps\Types\AiStep;
use MB\FilamentChatbot\Bot\Steps\Types\AiTaskStep;
use MB\FilamentChatbot\Bot\Steps\Types\AnswerStep;
use MB\FilamentChatbot\Bot\Steps\Types\ApprovalStep;
use MB\FilamentChatbot\Bot\Steps\Types\AskStep;
use MB\FilamentChatbot\Bot\Steps\Types\CallStep;
use MB\FilamentChatbot\Bot\Steps\Types\ChooseStep;
use MB\FilamentChatbot\Bot\Steps\Types\CloseStep;
use MB\FilamentChatbot\Bot\Steps\Types\CollectStep;
use MB\FilamentChatbot\Bot\Steps\Types\ConditionStep;
use MB\FilamentChatbot\Bot\Steps\Types\DelayStep;
use MB\FilamentChatbot\Bot\Steps\Types\ForEachStep;
use MB\FilamentChatbot\Bot\Steps\Types\FormStep;
use MB\FilamentChatbot\Bot\Steps\Types\HandoffStep;
use MB\FilamentChatbot\Bot\Steps\Types\HttpStep;
use MB\FilamentChatbot\Bot\Steps\Types\JumpStep;
use MB\FilamentChatbot\Bot\Steps\Types\KeywordStep;
use MB\FilamentChatbot\Bot\Steps\Types\MutateStep;
use MB\FilamentChatbot\Bot\Steps\Types\QueryStep;
use MB\FilamentChatbot\Bot\Steps\Types\ResultStep;
use MB\FilamentChatbot\Bot\Steps\Types\SayStep;
use MB\FilamentChatbot\Bot\Steps\Types\SetStep;
use MB\FilamentChatbot\Bot\Steps\Types\StartStep;
use MB\FilamentChatbot\Bot\Steps\Types\TransformStep;
use MB\FilamentChatbot\Bot\Variables\TalkVariables;
use MB\FilamentChatbot\Console\Commands\DemoConversationCommand;
use MB\FilamentChatbot\Console\Commands\DoctorCommand;
use MB\FilamentChatbot\Console\Commands\RunQualityChecksCommand;
use MB\FilamentChatbot\Console\Commands\SeedDemoDataCommand;
use MB\FilamentChatbot\Knowledge\KnowledgeBaseSource;
use MB\FilamentChatbot\Policies\Policies;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Wires the package into the host application.
 *
 * Everything the package adds to a panel lives in {@see ChatbotPlugin} instead,
 * and that split is deliberate. A host may run several panels — a staff desk and
 * a partner portal — and only one of them should carry the chat screens. Register
 * panel pieces here and they would appear in every panel the application has,
 * with no way to opt out short of forking the provider.
 */
class ChatbotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chatbot.php', 'chatbot');

        /*
         * Channels are a second provider registered by this one rather than a
         * second entry in composer's discovery list.
         *
         * Two discovered providers is two things that can be half-installed. A
         * host that has one and not the other gets a package whose screens work
         * and whose messages never leave the building — and nothing anywhere
         * says which half is missing. Registering it here makes the pair
         * indivisible while keeping the channel wiring in its own file.
         */
        $this->app->register(ChannelsServiceProvider::class);

        /*
         * The registry is a singleton because host packages register their steps,
         * capabilities and channels into it during their own boot. A fresh
         * instance per resolution would quietly drop every one of them.
         */
        $this->app->singleton(ChatbotRegistry::class);

        /*
         * Singletons because the canvas resolves all three on every render, and
         * each of them walks the registry to describe every registered node kind
         * — work that produces the same answer for the whole request.
         */
        $this->app->singleton(ScenarioGraph::class);
        $this->app->singleton(GraphConfig::class);
        $this->app->singleton(ScenarioVersions::class);

        $this->app->singleton(AiGatewayFactory::class);
        $this->app->bind(AiGateway::class, fn ($app): AiGateway => $app->make(AiGatewayFactory::class)->make());

        /*
         * Without this binding the `ai` node has nothing to quote and always
         * takes its "could not" exit — technically correct and useless. The
         * binding is here rather than in the AI layer because the direction
         * matters: the model reads from the knowledge base, the knowledge base
         * knows nothing about a model.
         */
        $this->app->bind(KnowledgeSource::class, KnowledgeBaseSource::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chatbot');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'chatbot');

        $this->registerBuiltInSteps();
        $this->registerBuiltInSlots();
        $this->registerBuiltInConditions();
        $this->registerBuiltInVariableSources();
        $this->registerPolicies();
        $this->registerAssets();

        if ($this->app->runningInConsole()) {
            $this->publishesTo();
            $this->registerCommands();
        }
    }

    /**
     * Points each model at its policy.
     *
     * Registered here rather than left to Laravel's convention, because the
     * convention looks for `App\Policies\ScenarioPolicy` in the host — which
     * does not exist, so the model would be authorised by nothing at all. That
     * failure is invisible: Laravel reads a model with no policy as having no
     * opinion, and the screen simply opens for everybody.
     */
    protected function registerPolicies(): void
    {
        foreach (Policies::map() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * The scenario canvas, registered but not loaded until a page asks for it.
     *
     * `loadedOnRequest()` is the whole point: the bundle is most of a megabyte —
     * React and a graph library are not small — and it is useful on exactly one
     * screen. Registered normally it would be downloaded by every person who
     * opened any page of the panel, to draw nothing.
     *
     * The files come from `dist/`, which is committed. A package cannot ask the
     * host to run a bundler, so the build happens here and the result ships.
     */
    protected function registerAssets(): void
    {
        FilamentAsset::register([
            Js::make('chatbot-graph', __DIR__.'/../dist/chatbot-graph.js')->loadedOnRequest(),
            Css::make('chatbot-graph', __DIR__.'/../dist/chatbot-graph.css')->loadedOnRequest(),
        ], package: 'mb4it/filament-chatbot');
    }

    /**
     * Console commands, added only when there is a console to add them to.
     *
     * Registered unconditionally they would be resolved on every web request for
     * nothing, and the demo seeder in particular has no business being reachable
     * from anywhere but a terminal.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            SeedDemoDataCommand::class,
            DemoConversationCommand::class,
            DoctorCommand::class,
            RunQualityChecksCommand::class,
        ]);
    }

    /**
     * Adds the package's own node kinds without ever displacing the host's.
     *
     * A key already claimed is left alone, for the same reason the slots below
     * are. Replacing a built-in node is the documented way to change one detail
     * of it — the wording it sends, the bound it enforces — and a host that does
     * so writes one line in a provider that may boot before this one or after
     * it, depending only on where the package sits in the manifest. Registering
     * unconditionally would honour that line on some installations and revert it
     * on others, which is the worst of both: it works on the developer's machine
     * and quietly does not in production.
     *
     * The list is written out rather than discovered by scanning the directory.
     * Scanning would pick up whatever happens to be lying in it — a half-written
     * node, a class kept for a migration — and put it on the palette of every
     * installation, and a node reaching the palette is a node a customer can be
     * routed through.
     *
     * Nodes the other subsystems own — collect, form, condition — register
     * themselves alongside their own machinery, and belong in this method as
     * they arrive.
     */
    protected function registerBuiltInSteps(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        $steps = [
            StartStep::class,
            SayStep::class,
            AskStep::class,
            CollectStep::class,
            FormStep::class,
            KeywordStep::class,
            ConditionStep::class,
            SetStep::class,
            DelayStep::class,
            JumpStep::class,
            CallStep::class,
            ResultStep::class,
            AnswerStep::class,
            AiStep::class,
            AiTaskStep::class,
            QueryStep::class,
            ChooseStep::class,
            MutateStep::class,
            ApprovalStep::class,
            ActionStep::class,
            HttpStep::class,
            TransformStep::class,
            ForEachStep::class,
            HandoffStep::class,
            CloseStep::class,
        ];

        foreach ($steps as $step) {
            if ($registry->hasStep($step::key())) {
                continue;
            }

            $registry->registerStep($step);
        }
    }

    /**
     * Adds the package's own slots without ever displacing the host's.
     *
     * Registration is skipped for a key that is already taken, and the skip is
     * the point. Providers boot in load order — this one before the host's own,
     * but after any package the host loaded earlier — so a plain
     * registerSlots() would win against half the application and lose against
     * the other half depending on nothing more than the order of a manifest. A
     * host that redefines "phone" with its own label and question would see it
     * silently reverted, and only on some installs.
     *
     * Skipping taken keys makes both directions agree: whoever registers a key
     * other than this package owns it, whether they got there first or arrive
     * later and overwrite.
     */
    protected function registerBuiltInSlots(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        foreach (BuiltInSlots::all() as $slot) {
            if ($registry->slot($slot->key()) !== null) {
                continue;
            }

            $registry->registerSlot($slot);
        }
    }

    /**
     * Adds the package's own condition fields, leaving claimed keys alone.
     *
     * Same rule as the nodes and the slots above, and for the same reason: which
     * provider boots first depends on the manifest, so registering
     * unconditionally would honour a host's override on some installations and
     * revert it on others.
     */
    protected function registerBuiltInConditions(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        $conditions = [
            VariableCondition::class,
            ChannelCondition::class,
            IdentifiedCondition::class,
            ReturningCondition::class,
            WorkingHoursCondition::class,
            TimeCondition::class,
            WeekdayCondition::class,
            MonthCondition::class,
            DayCondition::class,
            DateCondition::class,
            TurnsCondition::class,
            OnlyGreetingCondition::class,
            PageCondition::class,
            HandedOffCondition::class,
        ];

        foreach ($conditions as $condition) {
            if ($registry->condition($condition::key()) !== null) {
                continue;
            }

            $registry->registerCondition($condition);
        }
    }

    protected function registerBuiltInVariableSources(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        $talk = $this->app->make(TalkVariables::class);

        if ($registry->variableSource($talk->key()) === null) {
            $registry->registerVariableSource($talk);
        }
    }

    /**
     * Publishable groups, one tag per kind.
     *
     * Migrations go through publishesMigrations() rather than a plain publishes()
     * so that Laravel stamps a fresh timestamp on the copy. Published with their
     * package filenames, they would sort before migrations the host already has
     * and try to add foreign keys to tables that do not exist yet.
     */
    protected function publishesTo(): void
    {
        $this->publishes([
            __DIR__.'/../config/chatbot.php' => config_path('chatbot.php'),
        ], 'chatbot-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'chatbot-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/chatbot'),
        ], 'chatbot-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/chatbot'),
        ], 'chatbot-lang');
    }
}
