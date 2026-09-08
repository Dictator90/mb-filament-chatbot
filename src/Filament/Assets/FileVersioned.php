<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Assets;

/**
 * Versions an asset by the file rather than by the package's release number.
 *
 * Filament stamps `?v=` with the installed version of the package the asset
 * belongs to, which is exactly right for a tagged release and useless for
 * anything else. An installation tracking `dev-master` — every one during
 * development, and the two this package is installed in today — gets
 * `?v=dev-master` for the life of the branch: rebuild the editor, publish it,
 * reload the page, and the browser serves the bundle it cached in March
 * because the address has not changed.
 *
 * The symptom is not a stale-looking screen. It is the *new* server talking to
 * the *old* editor: a node kind the bundle has never heard of draws as a plain
 * text box, a field it cannot render silently falls through to the default, and
 * everything looks like a bug in the feature that was just written. It cost an
 * afternoon the first time, and it costs it again for whoever hits it next,
 * because nothing on the screen says which half is out of date.
 *
 * The modification time rather than a content hash: it changes on every build,
 * costs one `stat` at registration, and the thing being defended against is a
 * cached copy of a file that has since been rebuilt — not two files that
 * happen to be identical.
 *
 * A missing file falls back to the package version. That is the pruned-vendor
 * case, where the asset does not load at all and its address is the least of
 * anybody's problems.
 */
trait FileVersioned
{
    public function getVersion(): string
    {
        $path = $this->getPath();
        $modified = is_file($path) ? filemtime($path) : false;

        return $modified === false ? parent::getVersion() : (string) $modified;
    }
}
