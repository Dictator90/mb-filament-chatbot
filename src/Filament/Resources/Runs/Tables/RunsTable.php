<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Runs\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;

class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Both relations are columns, so both are loaded with the page.
             * The journal is the one list that grows without anybody
             * maintaining it — a row per conversation the bot touched — so a
             * query per row here is the difference between a page that opens
             * and one that times out.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['scenario', 'conversation']))
            ->columns([
                TextColumn::make('conversation_id')
                    ->label(__('chatbot::panel.runs.conversation'))
                    ->state(fn (Run $record): string => $record->conversation?->title()
                        ?? __('chatbot::panel.runs.conversation_gone')),
                /*
                 * The scenario name with the recorded slug as a fallback: a
                 * scenario can be deleted while its runs stay, and an empty
                 * cell would read as a trace belonging to nothing.
                 */
                TextColumn::make('scenario_slug')
                    ->label(__('chatbot::panel.runs.scenario'))
                    ->state(fn (Run $record): string => $record->scenario?->name ?? (string) $record->scenario_slug)
                    ->description(fn (Run $record): ?string => $record->scenario === null
                        ? __('chatbot::panel.runs.scenario_deleted')
                        : null)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('channel')
                    ->label(__('chatbot::panel.attributes.channel'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('outcome')
                    ->label(__('chatbot::panel.runs.outcome'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('steps_count')
                    ->label(__('chatbot::panel.runs.steps'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label(__('chatbot::panel.runs.started'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('scenario_id')
                    ->label(__('chatbot::panel.runs.scenario'))
                    ->options(fn (): array => Scenario::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('outcome')
                    ->label(__('chatbot::panel.runs.outcome'))
                    ->options(RunOutcome::class),
            ])
            ->recordActions(ActionGroup::make([
                ViewAction::make(),
            ]))
            /* No bulk actions: the journal cannot be edited or deleted from here. */
            ->toolbarActions([])
            ->emptyStateHeading(__('chatbot::panel.runs.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.runs.table.empty_description'));
    }
}
