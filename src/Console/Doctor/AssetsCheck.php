<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;

/**
 * Are the canvas files where the browser will ask for them.
 *
 * This is the failure that looks like nothing. The scenario screen renders,
 * the layout is correct, and where the diagram should be there is an empty
 * rectangle; the only evidence is a 404 in a console nobody has open. Somebody
 * then reports that "the editor is broken" and the search starts in the graph
 * code, which is fine.
 *
 * It happens on every deployment that forgets `filament:assets`, and it happens
 * again after an upgrade, because the published copy is the old bundle and
 * nothing about the page says so. Asking Filament for the assets it has
 * registered — rather than checking a hard-coded path — means this check keeps
 * working if the package renames a bundle or the host moves its assets
 * directory.
 */
final class AssetsCheck extends DoctorCheck
{
    protected const string PACKAGE = 'mb4it/filament-chatbot';

    public static function key(): string
    {
        return 'assets';
    }

    public function title(): string
    {
        return 'Published assets';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $assets = [
            ...FilamentAsset::getScripts([self::PACKAGE], withCore: false),
            ...FilamentAsset::getStyles([self::PACKAGE]),
        ];

        if ($assets === []) {
            return [DoctorFinding::skipped('The package registered no assets with Filament.')];
        }

        $missing = [];

        foreach ($assets as $asset) {
            if ($asset instanceof Asset && ! $asset->isRemote() && ! file_exists($asset->getPublicPath())) {
                $missing[] = $asset->getPublicPath();
            }
        }

        if ($missing !== []) {
            return [DoctorFinding::fail(
                count($missing).' of the canvas files are not published.',
                'Run php artisan filament:assets. Until then the scenario screen draws an empty rectangle and says nothing about why.',
                $missing,
            )];
        }

        return [DoctorFinding::pass(count($assets).' canvas files are published.')];
    }
}
