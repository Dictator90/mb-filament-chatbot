<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;

class EditWidget extends EditRecord
{
    protected static string $resource = WidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
