<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\KnowledgeEntry;

class KnowledgeEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('bot'))
            ->columns([
                TextColumn::make('topic_key')
                    ->label(__('chatbot::panel.knowledge.table.topic'))
                    /*
                     * The question sits under the key rather than in a column
                     * of its own: the key is what an import and a scenario
                     * name the topic by, and the question is what a person
                     * recognises it by. Both are needed, and only one of them
                     * needs the width.
                     */
                    ->description(fn (KnowledgeEntry $record): string => (string) $record->question)
                    ->searchable(['topic_key', 'question'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('category')
                    ->label(__('chatbot::panel.attributes.category'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('mode')
                    ->label(__('chatbot::panel.knowledge.mode'))
                    ->badge()
                    /*
                     * The colour is spelled out here because KnowledgeMode
                     * carries a label and no colour, and this list is read to
                     * find the topics the bot refuses to speak on. Without it
                     * every mode arrives grey and the refusals hide among the
                     * answers.
                     */
                    ->color(fn (?KnowledgeMode $state): string => match ($state) {
                        KnowledgeMode::Answer => 'success',
                        KnowledgeMode::Verify => 'warning',
                        KnowledgeMode::Handoff => 'danger',
                        KnowledgeMode::Urgent => 'danger',
                        KnowledgeMode::Scenario => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.knowledge.table.active'))
                    ->boolean(),
                TextColumn::make('bot.name')
                    ->label(__('chatbot::panel.knowledge.bot'))
                    ->placeholder(__('chatbot::panel.knowledge.table.bot_placeholder'))
                    ->sortable(),
            ])
            ->defaultSort('topic_key')
            ->filters([
                SelectFilter::make('mode')
                    ->label(__('chatbot::panel.knowledge.mode'))
                    ->options(KnowledgeMode::class),
                TernaryFilter::make('is_active')->label(__('chatbot::panel.knowledge.table.active')),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading(__('chatbot::panel.knowledge.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.knowledge.table.empty_description'));
    }
}
