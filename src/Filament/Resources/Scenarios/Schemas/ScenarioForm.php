<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ScenarioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('chatbot::panel.scenarios.heading'))->schema([
                Grid::make(2)->schema([
                    /*
                     * An empty bot is a scenario every bot may run, not a
                     * scenario nobody runs. The placeholder says so, because
                     * the fallbacks and the handover are exactly the scenarios
                     * left unattached, and reading the blank as "unassigned"
                     * would have someone attach them one bot at a time.
                     */
                    Select::make('bot_id')
                        ->label(__('chatbot::panel.scenarios.bot'))
                        ->relationship('bot', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder(__('chatbot::panel.scenarios.bot_placeholder'))
                        ->helperText(__('chatbot::panel.scenarios.bot_help')),
                    TextInput::make('priority')
                        ->label(__('chatbot::panel.attributes.priority'))
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->helperText(__('chatbot::panel.scenarios.priority_help')),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('chatbot::panel.attributes.name'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (blank($get('slug'))) {
                                $set('slug', Str::slug($state ?? ''));
                            }
                        }),
                    /*
                     * Knowledge topics point at a scenario by slug, and a run
                     * records the slug it walked. Renaming one after the fact
                     * orphans both without an error anywhere: the topic leads
                     * to a scenario that no longer exists, and the journal
                     * stops matching the scenario it describes.
                     */
                    TextInput::make('slug')
                        ->label(__('chatbot::panel.attributes.slug'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('chatbot::panel.scenarios.slug_help')),
                ]),

                Toggle::make('is_active')
                    ->label(__('chatbot::panel.attributes.is_active'))
                    ->default(true)
                    ->helperText(__('chatbot::panel.scenarios.is_active_help')),

                Textarea::make('description')
                    ->label(__('chatbot::panel.attributes.description'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('chatbot::panel.scenarios.description_help')),
            ]),

            /*
             * The invocation contract is what an AI router reads to decide
             * which scenario an incoming message belongs to. It is a section
             * of its own because it is written for a reader that is not a
             * person, and mixing it into the description would get it written
             * as prose — which routes nothing.
             */
            Section::make(__('chatbot::panel.scenarios.invocation.heading'))
                ->description(__('chatbot::panel.scenarios.invocation.description'))
                ->schema([
                    Textarea::make('invocation.purpose')
                        ->label(__('chatbot::panel.scenarios.invocation.purpose'))
                        ->rows(2)
                        ->helperText(__('chatbot::panel.scenarios.invocation.purpose_help')),
                    Textarea::make('invocation.useWhen')
                        ->label(__('chatbot::panel.scenarios.invocation.use_when'))
                        ->rows(2)
                        ->helperText(__('chatbot::panel.scenarios.invocation.use_when_help')),
                    Repeater::make('invocation.doNotUseWhen')
                        ->label(__('chatbot::panel.scenarios.invocation.do_not_use_when'))
                        ->simple(TextInput::make('value')->required())
                        ->addActionLabel(__('chatbot::panel.scenarios.invocation.add_exception'))
                        ->defaultItems(0)
                        ->helperText(__('chatbot::panel.scenarios.invocation.do_not_use_when_help')),
                    Repeater::make('invocation.examples')
                        ->label(__('chatbot::panel.scenarios.invocation.examples'))
                        ->simple(TextInput::make('value')->required())
                        ->addActionLabel(__('chatbot::panel.scenarios.invocation.add_example'))
                        ->defaultItems(0)
                        ->helperText(__('chatbot::panel.scenarios.invocation.examples_help')),
                ]),
        ]);
    }
}
