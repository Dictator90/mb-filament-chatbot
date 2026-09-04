<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\DataResources\DataResourceResource;

class ListDataResources extends ListRecords
{
    protected static string $resource = DataResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
