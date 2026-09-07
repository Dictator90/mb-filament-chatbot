<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QuickReplies\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->availableTo(Filament::auth()->id()))
            ->columns([
                TextColumn::make('title')
                    ->label(__('chatbot::panel.attributes.title'))
                    ->description(fn (QuickReply $record): string => str($record->body)->limit(90)->value())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('shortcut')
                    ->label(__('chatbot::panel.attributes.shortcut'))
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : '/'.ltrim($state, '/'))
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('user_id')
                    ->label(__('chatbot::panel.quick_replies.owner'))
                    ->state(fn (QuickReply $record): string => $record->user_id === null
                        ? __('chatbot::panel.quick_replies.table.shared')
                        : __('chatbot::panel.quick_replies.table.mine'))
                    ->badge()
                    ->color(fn (QuickReply $record): string => $record->user_id === null ? 'success' : 'gray'),
                TextColumn::make('sort')
                    ->label(__('chatbot::panel.quick_replies.order'))
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.quick_replies.table.in_chat'))
                    ->boolean(),
            ])
            ->defaultSort('sort')
            ->filters([
                TernaryFilter::make('is_active')->label(__('chatbot::panel.quick_replies.offered')),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading(__('chatbot::panel.quick_replies.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.quick_replies.table.empty_description'));
    }
}
