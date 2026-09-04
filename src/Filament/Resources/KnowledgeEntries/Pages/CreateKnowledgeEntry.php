<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages;

use Filament\Resources\Pages\CreateRecord;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;

class CreateKnowledgeEntry extends CreateRecord
{
    protected static string $resource = KnowledgeEntryResource::class;
}
