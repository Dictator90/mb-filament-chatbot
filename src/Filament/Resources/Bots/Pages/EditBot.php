<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;

class EditBot extends EditRecord
{
    protected static string $resource = BotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
