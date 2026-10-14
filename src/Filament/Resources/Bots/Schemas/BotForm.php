<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Ai\AiGatewayFactory;

class BotForm
{
    /**
     * Two tabs, split by who answers the question the setting asks.
     *
     * The sections used to be one flat column, and the complaint about it was
     * exact: nothing said where the AI settings ended. A prompt, a provider and
     * a monthly ceiling sat between working hours and an origin list, so an
     * installation with no model configured still read as one where every
     * second field was about a model. The tabs are drawn along the only line
     * that matters to whoever fills them in — what the bot is (Basics) and what
     * thinks for it (AI).
     *
     * There was a third, Widget, and it is gone rather than moved: how a bot is
     * embedded is not a property of the bot. One bot belongs on the company site
     * in blue and on a partner's portal in the partner's green, which that tab
     * could not express at all — and the tag it produced carried the bot's own
     * slug onto a public page, so re-pointing it meant asking somebody to edit
     * their own website. It lives on the widget screen now.
     *
     * The budgets belong with the model rather than with the rest: they are a
     * ceiling on what a provider may be asked to do, and mean nothing at all on
     * an installation whose driver is "null".
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('bot')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('basics')
                        ->label(__('chatbot::panel.bots.tabs.basics'))
                        ->schema(self::basics()),
                    Tab::make('ai')
                        ->label(__('chatbot::panel.bots.tabs.ai'))
                        ->schema(self::ai()),
                    /*
                     * One tab per messenger inside this one. Nested rather than
                     * four tabs beside «Основное», because they are four shapes
                     * of the same answer to one question — where this bot can
                     * be reached — and a row of six tabs hides the two that are
                     * about the bot itself.
                     */
                    Tab::make('channels')
                        ->label(__('chatbot::panel.bots.tabs.channels'))
                        ->schema([
                            Tabs::make('bot_channels')
                                ->columnSpanFull()
                                ->tabs(BotChannels::tabs()),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function basics(): array
    {
        return [
            Section::make(__('chatbot::panel.bots.identity.heading'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('chatbot::panel.attributes.name'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        /*
                         * Fills the slug only while it is still empty. Deriving
                         * it on every rename would rewrite the slug of a bot
                         * already embedded on a site, and the site would go on
                         * asking for a name nothing answers to.
                         */
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (blank($get('slug'))) {
                                $set('slug', Str::slug($state ?? ''));
                            }
                        }),
                    /*
                     * The slug is what an embed code and a knowledge topic name
                     * the bot by, so it is typed once and then left alone.
                     * Renaming it after a widget is embedded does not break
                     * loudly — the site keeps asking for a bot that no longer
                     * answers to that name, and the chat simply stops opening.
                     */
                    TextInput::make('slug')
                        ->label(__('chatbot::panel.attributes.slug'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->helperText(__('chatbot::panel.bots.identity.slug')),
                ]),
                Toggle::make('is_active')
                    ->label(__('chatbot::panel.attributes.is_active'))
                    ->default(true)
                    ->helperText(__('chatbot::panel.bots.identity.is_active')),
            ]),

            Section::make(__('chatbot::panel.bots.voice.heading'))->schema([
                Textarea::make('greeting')
                    ->label(__('chatbot::panel.attributes.greeting'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('chatbot::panel.bots.voice.greeting')),
                Textarea::make('prompt')
                    ->label(__('chatbot::panel.bots.voice.prompt'))
                    ->rows(6)
                    ->maxLength(8000)
                    ->helperText(__('chatbot::panel.bots.voice.prompt_help')),
            ]),

            Section::make(__('chatbot::panel.bots.hours.heading'))
                ->description(__('chatbot::panel.bots.hours.description'))
                ->schema([
                    Grid::make(2)->schema([
                        TimePicker::make('hours_from')
                            ->label(__('chatbot::panel.bots.hours.from'))
                            ->seconds(false),
                        TimePicker::make('hours_till')
                            ->label(__('chatbot::panel.bots.hours.until'))
                            ->seconds(false),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function ai(): array
    {
        return [
            Section::make(__('chatbot::panel.bots.model.heading'))
                ->description(__('chatbot::panel.bots.model.description'))
                ->schema([
                    Grid::make(2)->schema([
                        /*
                         * A list of registered drivers, never a text box.
                         *
                         * The factory declines an unknown name instead of
                         * throwing — a typo in a config file must not take the
                         * panel and every conversation down with it — so a
                         * misspelled driver typed here produces a bot that
                         * answers everything scripted and silently refuses to
                         * think, with nothing on the screen to say why. The
                         * options come from the factory rather than from a
                         * literal so that a driver a host registered through
                         * extend() is as choosable as a built-in one; an author
                         * has no way to tell which is which, and a hard-coded
                         * list would leave the host's own provider unreachable
                         * from the only screen that picks one.
                         */
                        Select::make('ai_driver')
                            ->label(__('chatbot::panel.bots.model.driver'))
                            ->options(fn (): array => app(AiGatewayFactory::class)->names())
                            ->placeholder(__('chatbot::panel.bots.model.driver_placeholder'))
                            ->live()
                            ->helperText(fn (): string => __('chatbot::panel.bots.model.driver_help', [
                                'driver' => self::defaultDriverName(),
                            ])),
                        /*
                         * Left as free text on purpose: model names are the one
                         * thing no registry here knows. They are a provider's
                         * own catalogue, they change without a release of this
                         * package, and a Select would be a list that is wrong
                         * the week after it is written.
                         */
                        TextInput::make('ai_model')
                            ->label(__('chatbot::panel.bots.model.model'))
                            ->maxLength(191)
                            ->placeholder(__('chatbot::panel.bots.model.model_placeholder'))
                            ->helperText(__('chatbot::panel.bots.model.model_help')),
                    ]),

                    /*
                     * Says out loud that nothing here is switched on.
                     *
                     * "null" is the shipped default and a perfectly ordinary way
                     * to run this package: scenarios and the knowledge base
                     * answer without a model at all. What is not ordinary is
                     * filling in a system prompt, a model name and a monthly
                     * budget and getting no model — three fields that all look
                     * accepted, and a bot that never uses any of them.
                     */
                    Text::make(fn (): string => __('chatbot::panel.bots.model.disabled_notice'))
                        ->key('ai_disabled_notice')
                        ->color('warning')
                        ->visible(fn (Get $get): bool => self::effectiveDriver($get('ai_driver')) === 'null'),
                ]),

            Section::make(__('chatbot::panel.bots.budget.heading'))
                ->description(__('chatbot::panel.bots.budget.description'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('monthly_token_budget')
                            ->label(__('chatbot::panel.bots.budget.tokens'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('monthly_cost_budget')
                            ->label(__('chatbot::panel.bots.budget.cost'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('chatbot::panel.bots.budget.cost_help')),
                    ]),
                ]),
        ];
    }

    /**
     * The driver this bot would actually use, with an empty field meaning the
     * installation default — which is what the runtime does with it.
     */
    protected static function effectiveDriver(mixed $chosen): string
    {
        $chosen = trim((string) ($chosen ?? ''));

        return $chosen !== '' ? $chosen : self::defaultDriverName();
    }

    protected static function defaultDriverName(): string
    {
        $default = trim((string) config('chatbot.ai.default', 'null'));

        return $default !== '' ? $default : 'null';
    }
}
