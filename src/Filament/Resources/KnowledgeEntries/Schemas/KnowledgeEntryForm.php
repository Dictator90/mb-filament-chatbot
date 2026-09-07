<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\Scenario;

class KnowledgeEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Select::make('bot_id')
                    ->label(__('chatbot::panel.knowledge.bot'))
                    ->relationship('bot', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('chatbot::panel.knowledge.bot_placeholder'))
                    ->helperText(__('chatbot::panel.knowledge.bot_help')),
                /*
                 * The key an import recognises a topic by. Editing it after
                 * the fact detaches the row from its source: the next import
                 * creates a second topic beside it and leaves this one an
                 * orphan nobody updates again.
                 */
                TextInput::make('topic_key')
                    ->label(__('chatbot::panel.knowledge.topic_key'))
                    ->required()
                    ->maxLength(191)
                    ->disabledOn('edit')
                    ->helperText(__('chatbot::panel.knowledge.topic_key_help')),
                TextInput::make('category')
                    ->label(__('chatbot::panel.attributes.category'))
                    ->maxLength(191),
            ]),

            Textarea::make('question')
                ->label(__('chatbot::panel.attributes.question'))
                ->required()
                ->rows(2)
                ->maxLength(2000)
                ->helperText(__('chatbot::panel.knowledge.question_help')),

            Grid::make(2)->schema([
                Select::make('mode')
                    ->label(__('chatbot::panel.knowledge.mode'))
                    ->options(KnowledgeMode::class)
                    ->default(KnowledgeMode::Answer)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                TextInput::make('priority')
                    ->label(__('chatbot::panel.attributes.priority'))
                    ->maxLength(32)
                    ->helperText(__('chatbot::panel.knowledge.priority_help')),
            ]),

            /*
             * Hidden while the mode is Handoff, and this is the whole point of
             * the mode rather than tidiness.
             *
             * Handoff means the bot never speaks on this topic — price, refund
             * windows, house rules — and the runtime does not read the answer
             * text at all. A visible answer field there is a promise the
             * runtime will not keep: whoever fills it in believes the topic is
             * answered, sees nothing wrong on the screen, and only finds out
             * that every one of those conversations went to a person when
             * somebody counts them. The field goes away so the mode reads as
             * what it is, a refusal.
             */
            Textarea::make('answer')
                ->label(__('chatbot::panel.attributes.answer'))
                ->rows(5)
                ->maxLength(8000)
                ->visible(fn (Get $get): bool => self::modeOf($get) !== KnowledgeMode::Handoff)
                ->required(fn (Get $get): bool => self::modeOf($get) === KnowledgeMode::Answer)
                ->helperText(__('chatbot::panel.knowledge.answer_help')),

            /*
             * Shown, and required, only for the mode that needs it: a topic
             * that starts a scenario without naming one is recognised and then
             * does nothing at all, which looks to a visitor exactly like a bot
             * that ignored them.
             */
            Select::make('scenario_slug')
                ->label(__('chatbot::panel.knowledge.scenario'))
                ->options(fn (): array => Scenario::query()->orderBy('name')->pluck('name', 'slug')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => self::modeOf($get) === KnowledgeMode::Scenario)
                ->required(fn (Get $get): bool => self::modeOf($get) === KnowledgeMode::Scenario)
                ->helperText(__('chatbot::panel.knowledge.scenario_help')),

            Repeater::make('keywords')
                ->label(__('chatbot::panel.attributes.keywords'))
                ->simple(TextInput::make('value')->required())
                ->addActionLabel(__('chatbot::panel.knowledge.add_keyword'))
                ->defaultItems(0)
                ->helperText(__('chatbot::panel.knowledge.keywords_help')),

            /*
             * Phrasings are not more keywords, and the two are separated
             * because they are confused constantly. A keyword catches a word
             * the topic already contains; a phrasing gives the topic a
             * vocabulary it has none of — "pup" resembles "animal" in no
             * letter, and no amount of matching will connect them.
             */
            Repeater::make('variants')
                ->label(__('chatbot::panel.knowledge.variants'))
                ->simple(TextInput::make('value')->required())
                ->addActionLabel(__('chatbot::panel.knowledge.add_variant'))
                ->defaultItems(0)
                ->helperText(__('chatbot::panel.knowledge.variants_help')),

            Toggle::make('is_active')
                ->label(__('chatbot::panel.attributes.is_active'))
                ->default(true)
                ->helperText(__('chatbot::panel.knowledge.is_active_help')),
        ]);
    }

    /**
     * The mode currently chosen in the form.
     *
     * Read through tryFrom() rather than cast: the select holds a raw string
     * while the form is being built and on a record whose mode predates a
     * rename, and from() would take the whole screen down over one unfamiliar
     * value.
     */
    private static function modeOf(Get $get): ?KnowledgeMode
    {
        $state = $get('mode');

        if ($state instanceof KnowledgeMode) {
            return $state;
        }

        return is_string($state) ? KnowledgeMode::tryFrom($state) : null;
    }
}
