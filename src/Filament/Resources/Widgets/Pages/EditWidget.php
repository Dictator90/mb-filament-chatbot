<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;

class EditWidget extends EditRecord
{
    protected static string $resource = WidgetResource::class;

    /**
     * Shows every appearance setting filled in, not only the ones somebody
     * changed.
     *
     * A stored theme holds what was set and nothing else, so a form bound
     * straight to it draws one filled colour box and seven empty ones. An empty
     * box does not read as "the default" — it reads as "nothing", which is not a
     * colour this widget will ever be, and it leaves no way to find out what the
     * panel background currently is. Worse, the preview beside it is drawing
     * that background, so the screen contradicts itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['theme'] = WidgetTheme::resolved($data['theme'] ?? null);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
