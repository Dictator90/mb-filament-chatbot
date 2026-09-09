<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Writing a check: who is talking, what they say, and what must be true after.
 *
 * **Nothing here is required except the name and the lines.** A check with no
 * expectations passes for ever and guards nothing, and the description says so
 * out loud rather than validating it away — an empty expectation is a legitimate
 * step on the way to a full one, and a form that refused to save it would be a
 * form people fill in backwards.
 */
class QualityTestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('chatbot::panel.quality.conversation.heading'))
                ->description(__('chatbot::panel.quality.conversation.description'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('chatbot::panel.quality.name'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('chatbot::panel.quality.name_help')),

                    Grid::make(3)->schema([
                        Select::make('bot_id')
                            ->label(__('chatbot::panel.quality.bot'))
                            ->options(fn (): array => Bot::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder(__('chatbot::panel.quality.bot_placeholder'))
                            ->searchable(),

                        /*
                         * The slug is written beside the key by the same action
                         * that picks it. Without it a deleted scenario nulls the
                         * key, and the check silently becomes a check of the
                         * whole bot — passing while guarding something else
                         * entirely. `QualityTest::isOrphaned()` is what reads it
                         * back.
                         */
                        Select::make('scenario_id')
                            ->label(__('chatbot::panel.quality.scenario'))
                            ->options(fn (): array => Scenario::query()
                                ->orderByDesc('priority')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder(__('chatbot::panel.quality.scenario_placeholder'))
                            ->live()
                            ->afterStateUpdated(fn (Set $set, mixed $state): mixed => $set(
                                'scenario_slug',
                                Scenario::query()->whereKey($state)->value('slug'),
                            ))
                            ->helperText(__('chatbot::panel.quality.scenario_help')),
                        Hidden::make('scenario_slug'),

                        Select::make('channel')
                            ->label(__('chatbot::panel.quality.channel'))
                            ->options(fn (): array => collect(app(ChatbotRegistry::class)->channels())
                                ->mapWithKeys(fn (ChannelDriver $driver): array => [$driver->key() => $driver->label()])
                                ->all())
                            ->default('site')
                            ->placeholder(__('chatbot::panel.quality.channel_placeholder')),
                    ]),

                    Repeater::make('said')
                        ->label(__('chatbot::panel.quality.said'))
                        ->simple(TextInput::make('line')->maxLength(500)->required())
                        ->addActionLabel(__('chatbot::panel.quality.said_add'))
                        ->helperText(__('chatbot::panel.quality.said_help'))
                        ->reorderable()
                        ->default([]),
                ]),

            /*
             * What the visitor is, before they have said anything.
             *
             * Kept apart from the lines because it is the half people forget: a
             * check that walks the "we know who you are" branch has to say so,
             * and one written without it silently exercises the stranger's
             * branch and passes — guarding the path nobody was worried about.
             */
            Section::make(__('chatbot::panel.quality.who.heading'))
                ->description(__('chatbot::panel.quality.who.description'))
                ->collapsed()
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('context.display_name')
                            ->label(__('chatbot::panel.quality.who.display_name'))
                            ->maxLength(191),

                        TextInput::make('context.page')
                            ->label(__('chatbot::panel.quality.who.page'))
                            ->maxLength(255)
                            ->placeholder('/pricing'),

                        Toggle::make('context.identified')
                            ->label(__('chatbot::panel.quality.who.identified'))
                            ->helperText(__('chatbot::panel.quality.who.identified_help')),
                    ]),

                    KeyValue::make('context.variables')
                        ->label(__('chatbot::panel.quality.who.variables'))
                        ->keyLabel(__('chatbot::panel.quality.who.variable_name'))
                        ->valueLabel(__('chatbot::panel.quality.who.variable_value'))
                        ->helperText(__('chatbot::panel.quality.who.variables_help')),
                ]),

            Section::make(__('chatbot::panel.quality.expected.heading'))
                ->description(__('chatbot::panel.quality.expected.description'))
                ->schema([
                    Grid::make(2)->schema([
                        Repeater::make('expectations.says')
                            ->label(__('chatbot::panel.quality.expected.says'))
                            ->simple(TextInput::make('text')->maxLength(500))
                            ->addActionLabel(__('chatbot::panel.quality.expected.says_add'))
                            ->helperText(__('chatbot::panel.quality.expected.says_help'))
                            ->default([]),

                        Repeater::make('expectations.never_says')
                            ->label(__('chatbot::panel.quality.expected.never_says'))
                            ->simple(TextInput::make('text')->maxLength(500))
                            ->addActionLabel(__('chatbot::panel.quality.expected.never_says_add'))
                            ->helperText(__('chatbot::panel.quality.expected.never_says_help'))
                            ->default([]),
                    ]),

                    Grid::make(2)->schema([
                        Repeater::make('expectations.visits')
                            ->label(__('chatbot::panel.quality.expected.visits'))
                            ->simple(TextInput::make('key')->maxLength(191))
                            ->addActionLabel(__('chatbot::panel.quality.expected.visits_add'))
                            ->helperText(__('chatbot::panel.quality.expected.visits_help'))
                            ->default([]),

                        Select::make('expectations.ends_with')
                            ->label(__('chatbot::panel.quality.expected.ends_with'))
                            /*
                             * Exactly the four words the runner can answer with
                             * (`src/Quality/QualityRunner.php`, `ending()`), and
                             * no others. A fifth in this list would be a choice
                             * that never matches: the check would fail for ever
                             * against a bot doing precisely what it was asked.
                             */
                            ->options([
                                'closed' => __('chatbot::panel.quality.endings.closed'),
                                'handed_off' => __('chatbot::panel.quality.endings.handed_off'),
                                'waiting' => __('chatbot::panel.quality.endings.waiting'),
                                'finished' => __('chatbot::panel.quality.endings.finished'),
                            ])
                            ->placeholder(__('chatbot::panel.quality.expected.not_checked'))
                            ->helperText(__('chatbot::panel.quality.expected.ends_with_help')),
                    ]),

                    KeyValue::make('expectations.collected')
                        ->label(__('chatbot::panel.quality.expected.collected'))
                        ->keyLabel(__('chatbot::panel.quality.who.variable_name'))
                        ->valueLabel(__('chatbot::panel.quality.who.variable_value'))
                        ->helperText(__('chatbot::panel.quality.expected.collected_help')),
                ]),

            Grid::make(2)->schema([
                Toggle::make('is_required')
                    ->label(__('chatbot::panel.quality.is_required'))
                    ->helperText(__('chatbot::panel.quality.is_required_help')),

                Toggle::make('is_active')
                    ->label(__('chatbot::panel.quality.is_active'))
                    ->default(true)
                    ->helperText(__('chatbot::panel.quality.is_active_help')),
            ]),
        ]);
    }
}
