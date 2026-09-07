<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
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
                    ->label(__('chatbot::panel.attributes.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('bot.name')
                    ->label(__('chatbot::panel.scenarios.bot'))
                    ->placeholder(__('chatbot::panel.scenarios.table.bot_placeholder'))
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('chatbot::panel.attributes.slug'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('chatbot::panel.scenarios.table.active'))
                    ->boolean(),
                TextColumn::make('priority')
                    ->label(__('chatbot::panel.attributes.priority'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('steps_count')
                    ->label(__('chatbot::panel.scenarios.table.steps'))
                    ->alignEnd()
                    ->sortable(),
                /*
                 * An unpublished scenario still answers customers, so "never
                 * published" is a state worth seeing rather than an empty
                 * cell: while it runs as a draft, every edit reaches visitors
                 * the moment it is saved.
                 */
                TextColumn::make('liveVersion.number')
                    ->label(__('chatbot::panel.scenarios.table.live_version'))
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->isPublished() ? 'success' : 'warning')
                    ->formatStateUsing(fn (mixed $state): string => '#'.$state)
                    ->placeholder(__('chatbot::panel.scenarios.table.draft')),
                TextColumn::make('updated_at')
                    ->label(__('chatbot::panel.scenarios.table.updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                SelectFilter::make('bot_id')
                    ->label(__('chatbot::panel.scenarios.bot'))
                    ->options(fn (): array => Bot::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')->label(__('chatbot::panel.scenarios.table.active')),
            ])
            /*
             * The canvas is a button of its own, outside the group, and that is
             * the whole point of it being here.
             *
             * Drawing the graph is what a scenario is for; the form behind
             * "Edit" holds its name and its priority. Filed inside the three-dot
             * group the canvas sat one level below deleting the record and was
             * never seen: the page existed, answered on its own URL, and the
             * only way anybody reached it was by typing that URL. A row action
             * nobody finds is a page nobody has.
             */
            ->recordActions([
                Action::make('graph')
                    ->label(__('chatbot::panel.scenarios.table.graph'))
                    ->icon(Heroicon::OutlinedShare)
                    ->url(fn (Scenario $record): string => ScenarioResource::getUrl('graph', ['record' => $record])),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('chatbot::panel.scenarios.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.scenarios.table.empty_description'));
    }
}
