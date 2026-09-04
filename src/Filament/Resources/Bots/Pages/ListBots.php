<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;

/**
 * Creation happens in a modal rather than on a page of its own.
 *
 * The resource declares no create page, which is what makes Filament fall back
 * to the modal. A new bot needs a name and a slug and nothing else to exist;
 * sending someone to a full-page form for two fields, and to the edit screen
 * again for the rest, would be two round trips for one thought.
 */
class ListBots extends ListRecords
{
    protected static string $resource = BotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
