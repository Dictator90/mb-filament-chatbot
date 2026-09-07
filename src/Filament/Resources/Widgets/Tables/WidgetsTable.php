<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Models\Widget;

class WidgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * The bot is eager-loaded because two columns read it. Left to the
             * relation, ten widgets would cost eleven queries — growth nobody
             * notices until an installation has more than a couple of rows.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('bot'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('chatbot::panel.attributes.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('chatbot::panel.attributes.key'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                /*
                 * "Straight to a person" rather than a dash. A widget with no
                 * bot is a deliberate arrangement, and an empty cell would read
                 * as a row somebody forgot to finish.
                 */
                TextColumn::make('bot.name')
                    ->label(__('chatbot::panel.site_widgets.table.bot'))
                    ->placeholder(__('chatbot::panel.site_widgets.table.bot_none'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.attributes.is_active'))
                    ->boolean(),
                TextColumn::make('allowed_origins')
                    ->label(__('chatbot::panel.site_widgets.table.sites'))
                    ->state(fn (Widget $record): string => ($origins = array_filter((array) $record->allowed_origins)) === []
                        ? __('chatbot::panel.site_widgets.table.sites_any')
                        : (string) count($origins)),
                TextColumn::make('updated_at')
                    ->label(__('chatbot::panel.site_widgets.table.updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label(__('chatbot::panel.attributes.is_active')),
                SelectFilter::make('bot')
                    ->label(__('chatbot::panel.site_widgets.table.bot'))
                    ->relationship('bot', 'name'),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading(__('chatbot::panel.site_widgets.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.site_widgets.table.empty_description'));
    }
}
