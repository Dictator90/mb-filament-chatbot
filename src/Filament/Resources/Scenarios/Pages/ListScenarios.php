<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;

class ListScenarios extends ListRecords
{
    protected static string $resource = ScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
