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
                    /*
                     * Keywords are searched although no column shows them.
                     * They are the words a visitor actually types — «адрес»,
                     * «где вы» — and a topic is far more often remembered by
                     * one of those than by the heading somebody gave it.
                     *
                     * By `whereJsonContains`, and the plain `like` that looks
                     * right here never matches: the column is JSON, and PHP
                     * escapes anything non-ASCII into it as `ад…`,
                     * so a Cyrillic keyword is simply not in the stored text.
                     * The price is that a keyword matches whole — «адрес»
                     * finds it, «адре» does not — which is how people search
                     * for a word they themselves put there.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('topic_key', 'like', '%'.$search.'%')
                        ->orWhere('question', 'like', '%'.$search.'%')
                        ->orWhereJsonContains('keywords', $search))
                    ->sortable()
                    ->wrap(),
                /*
                 * The answer is in the list because the list is read to find a
                 * topic, and a base of two hundred rows is searched by what the
                 * bot says rather than by what the topic is called: «где-то
                 * здесь написано про возврат за сутки» is the question being
                 * asked, and the heading says «Отмена брони».
                 *
                 * Trimmed deliberately. The table is still the wrong place to
                 * read an answer in full, and a column that wrapped three
                 * paragraphs would push every other column off the screen.
                 */
                TextColumn::make('answer')
                    ->label(__('chatbot::panel.attributes.answer'))
                    ->placeholder('—')
                    ->limit(90)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category')
                    ->label(__('chatbot::panel.attributes.category'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('chatbot::panel.attributes.priority'))
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
