<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Schemas;

use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use MB\FilamentChatbot\Http\WidgetDraft;

/**
 * The widget as it will actually look, beside the form that decides it.
 *
 * A frame around the real `widget.js`, never a drawing of one. The temptation
 * is obvious — a div, a bubble, a rounded button, thirty lines and no request —
 * and it is the wrong thing: that drawing is a second implementation of the
 * widget's appearance, it drifts from the first the week after either changes,
 * and when it drifts the preview does not look broken. It looks authoritative
 * and is wrong, which is the only failure mode a preview cannot recover from.
 *
 * The draft goes into the frame's address rather than into storage. That is
 * what makes an unsaved change visible: a field the panel marks `live` changes
 * the URL, and a changed `src` is a reload the browser does by itself. Kept in
 * a cache entry instead, the address would stay the same, the frame would not
 * reload, and the preview would sit there showing the previous colour.
 */
final class WidgetPreview extends Component
{
    protected string $view = 'chatbot::widget-preview';

    public static function make(): static
    {
        $static = app(self::class);
        $static->configure();

        return $static;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraViewData(): array
    {
        return array_merge(parent::getExtraViewData(), [
            'previewUrl' => $this->evaluate(static fn (Get $get): string => WidgetDraft::url(
                (string) Filament::getId(),
                [
                    'theme' => (array) $get('theme'),
                    'labels' => (array) $get('labels'),
                    'greeting' => (string) $get('greeting'),
                ],
            )),
        ]);
    }
}
