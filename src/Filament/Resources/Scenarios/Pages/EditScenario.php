<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;

class EditScenario extends EditRecord
{
    protected static string $resource = ScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
