<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Tests\Support;

use MB\FilamentChatbot\Bot\Http\OutboundGuard;

/**
 * An outbound guard for which every name resolves to this machine.
 *
 * The one thing about the http node that cannot be shown with real DNS: a
 * domain sitting legitimately in the allow-list whose owner points it at
 * 127.0.0.1, which is this application's own web server behind whatever the
 * allow-list was written to permit. Nothing in the URL says so, so the only
 * honest test of the check is to answer the resolution with loopback and watch
 * the guard refuse a host it had just accepted by name.
 */
final class LoopbackGuard extends OutboundGuard
{
    /**
     * @return list<string>
     */
    protected function addressesFor(string $host): array
    {
        return ['127.0.0.1'];
    }
}
