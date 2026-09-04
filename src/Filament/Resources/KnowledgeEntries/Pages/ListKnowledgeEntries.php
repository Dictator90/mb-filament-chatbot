<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;

class ListKnowledgeEntries extends ListRecords
{
    protected static string $resource = KnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
