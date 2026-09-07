<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * The scenario count is a column, so it is counted in the same
             * query as the rows. Reading the relation per line would make ten
             * bots cost eleven queries — the sort of growth that stays
             * invisible until an installation has more than a couple of bots.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('scenarios'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('chatbot::panel.attributes.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('chatbot::panel.attributes.slug'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.bots.table.active'))
                    ->boolean(),
                TextColumn::make('scenarios_count')
                    ->label(__('chatbot::panel.bots.table.scenarios'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('chatbot::panel.bots.table.updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label(__('chatbot::panel.bots.table.active')),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading(__('chatbot::panel.bots.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.bots.table.empty_description'));
    }
}
