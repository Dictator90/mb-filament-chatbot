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
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('model')
                    ->label('Model')
                    ->searchable()
                    ->wrap(),
                /*
                 * The count is of fields that may actually leave the
                 * application, not of declared ones. A resource with twelve
                 * columns and every one of them sensitive answers nothing, and
                 * showing twelve here would hide exactly that.
                 */
                TextColumn::make('fields')
                    ->label('Returnable fields')
                    ->state(fn (DataResource $record): int => count($record->returnableFields()))
                    ->alignEnd()
                    ->badge()
                    ->color(fn (DataResource $record): string => $record->returnableFields() === [] ? 'danger' : 'gray'),
                TextColumn::make('modes')
                    ->label('Shapes')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => DataQueryMode::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                TextColumn::make('max_limit')
                    ->label('Ceiling')
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('key')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_writable')->label('Writable'),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading('No data resources declared')
            ->emptyStateDescription('A data resource is a declared window onto one of this application\'s models — the bot can read nothing that is not listed here.');
    }
}
