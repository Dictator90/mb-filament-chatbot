<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Runs\Pages;

use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Filament\Resources\Runs\RunResource;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    /**
     * The page shows the whole run, so its relations come up in one go.
     *
     * Without this a trace of twenty steps costs twenty queries, and in an
     * application that forbids lazy loading outside production it does not cost
     * queries at all — it throws, on the one screen somebody opened to find out
     * what went wrong.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['scenario', 'conversation', 'steps']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
