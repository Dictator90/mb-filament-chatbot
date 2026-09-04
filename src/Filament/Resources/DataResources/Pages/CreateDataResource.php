<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources\Pages;

use Filament\Resources\Pages\CreateRecord;
use MB\FilamentChatbot\Filament\Resources\DataResources\DataResourceResource;

class CreateDataResource extends CreateRecord
{
    protected static string $resource = DataResourceResource::class;
}
