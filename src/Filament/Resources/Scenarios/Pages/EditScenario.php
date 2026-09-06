<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
use MB\FilamentChatbot\Models\Scenario;

class EditScenario extends EditRecord
{
    protected static string $resource = ScenarioResource::class;

    /**
     * The canvas is reachable from here as well as from the list.
     *
     * The form and the graph are two pages of one record, and somebody who
     * opened the settings in order to draw the scenario has already gone one
     * page too far. Without this button the only way on is back to the list —
     * or the URL, which is how the canvas was found at all before either link
     * existed.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('graph')
                ->label('Graph')
                ->icon(Heroicon::OutlinedShare)
                ->url(fn (Scenario $record): string => ScenarioResource::getUrl('graph', ['record' => $record])),
            DeleteAction::make(),
        ];
    }
}
