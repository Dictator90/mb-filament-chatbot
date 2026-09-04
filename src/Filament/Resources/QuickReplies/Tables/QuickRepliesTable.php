<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QuickReplies\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Models\QuickReply;

class QuickRepliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * The same selection the reply box makes. Another operator's
             * personal reply must not reach even this list: seen here and
             * missing from the chat, it reads as a broken picker.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->availableTo(auth()->id()))
            ->columns([
                TextColumn::make('title')
                    ->description(fn (QuickReply $record): string => str($record->body)->limit(90)->value())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('shortcut')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : '/'.ltrim($state, '/'))
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('user_id')
                    ->label('Whose')
                    ->state(fn (QuickReply $record): string => $record->user_id === null ? 'shared' : 'mine')
                    ->badge()
                    ->color(fn (QuickReply $record): string => $record->user_id === null ? 'success' : 'gray'),
                TextColumn::make('sort')
                    ->label('Order')
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('In chat')
                    ->boolean(),
            ])
            ->defaultSort('sort')
            ->filters([
                TernaryFilter::make('is_active')->label('Offered in the chat'),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading('No quick replies yet')
            ->emptyStateDescription('Save the sentence you type most often — in the chat it drops in with one key.');
    }
}
