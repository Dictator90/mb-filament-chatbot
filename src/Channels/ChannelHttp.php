<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Models\ChannelConnection;

/**
 * Every outgoing call to a messenger, built the same way.
 *
 * Exists for the proxy. Some networks cannot reach some providers at all —
 * a Russian host calling Telegram's API is the case this was written for, and
 * it fails as a ten-second silence rather than as a refusal, which reads on
 * screen like a bug in this package. Routing those calls through a proxy is a
 * property of the account rather than of the driver, so it lives on the
 * connection and is applied here, once, instead of in nine places that would
 * drift.
 *
 * **Only the calls this application makes.** The webhook travels the other way:
 * the provider connects to this server, and no proxy of ours is in that path.
 * An installation whose inbound is blocked as well needs polling, which this
 * package does not do — and saying so is more useful than a setting that
 * half-works.
 */
final class ChannelHttp
{
    public static function for(string $channel, ?ChannelConnection $connection = null): PendingRequest
    {
        $request = Http::timeout((int) config("chatbot.channels.{$channel}.timeout", 10));
        $proxy = self::proxyFor($channel, $connection);

        return $proxy === '' ? $request : $request->withOptions(['proxy' => $proxy]);
    }

    /**
     * The proxy this account speaks through, or nothing.
     *
     * The connection wins over config on purpose: two bots on one installation
     * can have accounts at providers with different reachability, and the
     * installation-wide value is the fallback for the common case where they
     * do not.
     */
    public static function proxyFor(string $channel, ?ChannelConnection $connection = null): string
    {
        $fromConnection = $connection?->credential('proxy') ?? '';

        if ($fromConnection !== '') {
            return $fromConnection;
        }

        return trim((string) config("chatbot.channels.{$channel}.proxy", ''));
    }
}
