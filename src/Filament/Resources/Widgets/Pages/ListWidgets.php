<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;

class ListWidgets extends ListRecords
{
    protected static string $resource = WidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
