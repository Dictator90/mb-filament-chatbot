<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\Request;
use MB\FilamentChatbot\Channels\ChannelConnections;
use MB\FilamentChatbot\Channels\ChannelSetup;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where every messenger delivers.
 *
 * One controller for all of them, because what differs between Telegram, MAX,
 * WhatsApp and VK is entirely inside the driver — how the caller proves it is
 * genuine, what an update looks like, what the provider wants back — and what
 * they share is everything around it: find the account, store the message, let
 * the bot answer. A controller per messenger would have four copies of the
 * shared half and would drift the first time one of them was fixed.
 *
 * **The address carries the account.** A provider's POST says nothing about
 * which bot it belongs to, so the path segment does: it is the connection's
 * unguessable id, and resolving it is what makes two bots with two Telegram
 * accounts possible on one installation.
 *
 * **An unknown address is a 404 and nothing else.** Not "unknown connection",
 * not a hint about which part was wrong: the address is the only secret in the
 * URL, and a different answer for a real-but-inactive connection would tell a
 * stranger they had guessed one.
 */
class ChannelWebhookController
{
    public function __construct(
        private readonly ChatbotRegistry $registry,
        private readonly ChannelConnections $connections,
        private readonly MessageRouter $router,
    ) {}

    public function __invoke(Request $request, string $channel, string $connection): Response
    {
        $driver = $this->registry->channel($channel);

        if (! $driver instanceof ChannelSetup) {
            abort(404);
        }

        $account = $this->connections->byPublicId($channel, $connection);

        if ($account === null) {
            abort(404);
        }

        /*
         * A GET is never an update. It is WhatsApp's verification handshake,
         * answered by the driver with the challenge it was sent — and it
         * arrives before any secret has ever been exchanged, so running it
         * through the authenticity check would make the channel impossible to
         * connect in the first place. Nothing is read from it and nothing is
         * written.
         */
        if ($request->isMethod('GET')) {
            return $driver->acknowledge($request, $account);
        }

        if (! $driver->authentic($request, $account)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $inbound = $driver->decode($request, $account);

        if ($inbound !== null) {
            $this->router->receive($inbound);
        }

        /*
         * Stamped for anything that got past the check, including updates this
         * package makes nothing of. The question this answers on the settings
         * screen is "is the webhook arriving at all", and a sticker proves that
         * as well as a sentence does.
         */
        $account->forceFill(['last_event_at' => now()])->save();

        return $driver->acknowledge($request, $account);
    }
}
