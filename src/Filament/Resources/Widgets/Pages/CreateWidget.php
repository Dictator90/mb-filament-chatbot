<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Pages;

use Filament\Resources\Pages\CreateRecord;
use MB\FilamentChatbot\Channels\WidgetFeatures;
use MB\FilamentChatbot\Channels\WidgetTheme;
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

    /**
     * A new widget opens with every appearance setting already filled in.
     *
     * Blank boxes on a create form would leave somebody choosing colours against
     * nothing while the preview beside them shows a complete widget — and the
     * first thing they would do is guess at the seven values the preview is
     * plainly using.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['theme'] = WidgetTheme::defaults();
        $data['features'] = WidgetFeatures::defaults();

        return $data;
    }
}
