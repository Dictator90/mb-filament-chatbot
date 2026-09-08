<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Assets;

use Filament\Support\Assets\Css;

/**
 * The scenario canvas stylesheet, addressed by the file it actually is.
 *
 * See {@see FileVersioned}. The stylesheet matters as much as the script: a
 * rule the old sheet does not have makes a control the new bundle draws come
 * out unstyled, which reads as broken rather than as stale.
 */
class CanvasStyles extends Css
{
    use FileVersioned;
}
