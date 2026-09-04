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
            Section::make('Scenario')->schema([
                Grid::make(2)->schema([
                    /*
                     * An empty bot is a scenario every bot may run, not a
                     * scenario nobody runs. The placeholder says so, because
                     * the fallbacks and the handover are exactly the scenarios
                     * left unattached, and reading the blank as "unassigned"
                     * would have someone attach them one bot at a time.
                     */
                    Select::make('bot_id')
                        ->label('Bot')
                        ->relationship('bot', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder('Every bot')
                        ->helperText('Left empty, the scenario is available to every bot.'),
                    TextInput::make('priority')
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->helperText('Tried highest first when several scenarios fit the same opening line.'),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('name')
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
                        ->required()
                        ->maxLength(191)
                        ->helperText('Knowledge topics and past runs refer to a scenario by this. Renaming it orphans both silently.'),
                ]),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('A switched-off scenario is never picked, and a topic pointing at it hands the conversation to a person.'),

                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('For whoever edits the scenario next. Never shown to a visitor.'),
            ]),

            /*
             * The invocation contract is what an AI router reads to decide
             * which scenario an incoming message belongs to. It is a section
             * of its own because it is written for a reader that is not a
             * person, and mixing it into the description would get it written
             * as prose — which routes nothing.
             */
            Section::make('Invocation contract')
                ->description('Read by the routing model when AI routing is on. With AI off the bot falls back to the keywords on the start node, so a scenario has to make sense without any of this.')
                ->schema([
                    Textarea::make('invocation.purpose')
                        ->label('Purpose')
                        ->rows(2)
                        ->helperText('What this scenario is for, in one sentence.'),
                    Textarea::make('invocation.useWhen')
                        ->label('Use when')
                        ->rows(2)
                        ->helperText('The kind of message that belongs here.'),
                    Repeater::make('invocation.doNotUseWhen')
                        ->label('Do not use when')
                        ->simple(TextInput::make('value')->required())
                        ->addActionLabel('Add an exception')
                        ->defaultItems(0)
                        ->helperText('The near misses. A router with no exceptions listed picks the nearest scenario, and the nearest is often the wrong one.'),
                    Repeater::make('invocation.examples')
                        ->label('Examples')
                        ->simple(TextInput::make('value')->required())
                        ->addActionLabel('Add an example')
                        ->defaultItems(0)
                        ->helperText('Real openings, as visitors write them.'),
                ]),
        ]);
    }
}
