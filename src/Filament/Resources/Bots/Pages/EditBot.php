<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;
use MB\FilamentChatbot\Filament\Resources\Bots\Schemas\BotChannels;

class EditBot extends EditRecord
{
    protected static string $resource = BotResource::class;

    /**
     * The messenger tabs, which are rows of their own rather than columns.
     *
     * Carried in and out of the form by hand because the set of channels is
     * open: a host registers its own driver and gets its own tab, and
     * Filament's relationship binding needs a relation named in advance.
     *
     * @var array<string, array{is_active?: bool, credentials?: array<string, string>}>
     */
    protected array $channels = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['channels'] = BotChannels::state($this->getRecord());

        return $data;
    }

    /**
     * Takes the tabs out before the bot itself is saved.
     *
     * The bot's update is a mass assignment of this array, and a leftover
     * `channels` key would try to write a column that does not exist. The
     * connections are separate rows, written in {@see afterSave()}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->channels = (array) ($data['channels'] ?? []);

        unset($data['channels']);

        return $data;
    }

    protected function afterSave(): void
    {
        BotChannels::persist($this->getRecord(), $this->channels);
    }
}
