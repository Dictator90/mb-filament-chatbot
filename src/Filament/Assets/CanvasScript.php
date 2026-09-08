<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Assets;

use Filament\Support\Assets\Js;

/**
 * The scenario canvas bundle, addressed by the file it actually is.
 *
 * See {@see FileVersioned} for why the package's release number is the wrong
 * thing to stamp on it.
 */
class CanvasScript extends Js
{
    use FileVersioned;
}
