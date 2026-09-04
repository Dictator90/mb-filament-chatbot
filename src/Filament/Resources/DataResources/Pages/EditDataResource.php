<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\DataResources\DataResourceResource;

class EditDataResource extends EditRecord
{
    protected static string $resource = DataResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
