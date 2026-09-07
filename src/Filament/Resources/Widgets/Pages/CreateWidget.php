<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Pages;

use Filament\Resources\Pages\CreateRecord;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;

/**
 * A page of its own rather than the modal the bots use.
 *
 * The preview is the reason. Choosing a colour and a shape without seeing them
 * is the thing this screen exists to stop, and a modal has nowhere to put the
 * frame that shows them — so a widget created in one would be created blind and
 * then looked at afterwards, which is the workflow the preview replaces.
 */
class CreateWidget extends CreateRecord
{
    protected static string $resource = WidgetResource::class;
}
