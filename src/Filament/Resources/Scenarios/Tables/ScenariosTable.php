<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Scenario;

class ScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * The bot name and the live version are both columns, so both are
             * loaded with the page of rows. Left to themselves they would be
             * two queries per line, and a list of scenarios is exactly where
             * that goes unnoticed — there are rarely enough rows for it to
             * feel slow, and it never stops costing.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['bot', 'liveVersion'])
                ->withCount('steps'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('bot.name')
                    ->label('Bot')
                    ->placeholder('every bot')
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('priority')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('steps_count')
                    ->label('Steps')
                    ->alignEnd()
                    ->sortable(),
                /*
                 * An unpublished scenario still answers customers, so "never
                 * published" is a state worth seeing rather than an empty
                 * cell: while it runs as a draft, every edit reaches visitors
                 * the moment it is saved.
                 */
                TextColumn::make('liveVersion.number')
                    ->label('Live version')
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->isPublished() ? 'success' : 'warning')
                    ->formatStateUsing(fn (mixed $state): string => '#'.$state)
                    ->placeholder('draft'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                SelectFilter::make('bot_id')
                    ->label('Bot')
                    ->options(fn (): array => Bot::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions(ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ]))
            ->emptyStateHeading('No scenarios yet')
            ->emptyStateDescription('A scenario is one conversation the bot knows how to have, from its opening line to a handover or a close.');
    }
}
