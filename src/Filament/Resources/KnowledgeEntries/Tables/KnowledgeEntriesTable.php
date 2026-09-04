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
                    ->label('Topic')
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
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('mode')
                    ->label('How to answer')
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
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('bot.name')
                    ->label('Bot')
                    ->placeholder('every bot')
                    ->sortable(),
            ])
            ->defaultSort('topic_key')
            ->filters([
                SelectFilter::make('mode')
                    ->label('How to answer')
                    ->options(KnowledgeMode::class),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading('No topics yet')
            ->emptyStateDescription('A topic is a question the bot meets often — with an answer, or with an instruction not to give one.');
    }
}
