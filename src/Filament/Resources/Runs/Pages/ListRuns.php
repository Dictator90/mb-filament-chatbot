<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Runs\Pages;

use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\Runs\RunResource;

/**
 * A list with no header actions: the journal has no create, no edit, no delete.
 */
class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
