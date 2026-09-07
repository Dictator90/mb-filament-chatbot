<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Models\DataResource;

class DataResourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label(__('chatbot::panel.attributes.key'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->label(__('chatbot::panel.attributes.label'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('model')
                    ->label(__('chatbot::panel.data_resources.table.model'))
                    ->searchable()
                    ->wrap(),
                /*
                 * The count is of fields that may actually leave the
                 * application, not of declared ones. A resource with twelve
                 * columns and every one of them sensitive answers nothing, and
                 * showing twelve here would hide exactly that.
                 */
                TextColumn::make('fields')
                    ->label(__('chatbot::panel.data_resources.table.returnable'))
                    ->state(fn (DataResource $record): int => count($record->returnableFields()))
                    ->alignEnd()
                    ->badge()
                    ->color(fn (DataResource $record): string => $record->returnableFields() === [] ? 'danger' : 'gray'),
                TextColumn::make('modes')
                    ->label(__('chatbot::panel.data_resources.table.shapes'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => DataQueryMode::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                TextColumn::make('max_limit')
                    ->label(__('chatbot::panel.data_resources.table.ceiling'))
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.data_resources.table.active'))
                    ->boolean(),
            ])
            ->defaultSort('key')
            ->filters([
                TernaryFilter::make('is_active')->label(__('chatbot::panel.data_resources.table.active')),
                TernaryFilter::make('is_writable')->label(__('chatbot::panel.data_resources.table.writable')),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading(__('chatbot::panel.data_resources.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.data_resources.table.empty_description'));
    }
}
