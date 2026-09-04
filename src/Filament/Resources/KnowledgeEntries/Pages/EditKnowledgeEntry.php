<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;

class EditKnowledgeEntry extends EditRecord
{
    protected static string $resource = KnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
