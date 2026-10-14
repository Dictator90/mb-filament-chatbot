<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use Illuminate\Http\Request;
use MB\FilamentChatbot\Models\ChannelConnection;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a channel needs in order to be wired up from the settings screen.
 *
 * Separate from {@see ChannelDriver} because the two are needed at different
 * times by different code, and a channel can honestly have one without the
 * other. The site widget delivers messages and has nothing to set up; a host's
 * own gateway may be configured entirely outside this application and still
 * want to deliver through the package. Folding these methods into the driver
 * contract would force both of those to implement ceremony they have no use
 * for.
 *
 * Everything here is per connection — per bot's account — rather than per
 * installation, which is the whole reason the contract exists: the same
 * Telegram driver answers for two bots with two tokens, and only the row knows
 * which is which.
 */
interface ChannelSetup
{
    /**
     * The credential keys this provider needs, in the order a person fills them.
     *
     * Read by the settings screen to draw the fields and by the driver to say
     * whether a connection is finished. One list rather than two so that adding
     * a field to a provider cannot leave the form and the check disagreeing
     * about what "configured" means.
     *
     * @return array<string, array{label: string, secret?: bool, hint?: string, required?: bool}>
     */
    public function credentialFields(): array;

    /**
     * Asks the provider whether these credentials are good.
     *
     * A real call, not a shape check: a token with the right number of
     * characters and no account behind it is exactly the state that looks
     * configured on screen and fails on the first customer.
     */
    public function verify(ChannelConnection $connection): ChannelStatus;

    /**
     * Points the provider's webhook at this connection's address.
     *
     * Returns a manual status where the provider has no API for it, carrying
     * what a person has to paste and where.
     */
    public function installWebhook(ChannelConnection $connection, string $url): ChannelStatus;

    /**
     * Whether this request really came from the provider.
     *
     * Each messenger authenticates differently — a shared secret in a header,
     * a secret inside the body, a signature over the raw payload — and the one
     * thing they have in common is that getting it wrong lets a stranger write
     * into the customer desk as if they were a client.
     */
    public function authentic(Request $request, ChannelConnection $connection): bool;

    /**
     * Turns one delivered payload into an inbound message, or into nothing.
     *
     * Nothing is ordinary: a sticker, a delivery receipt, a member joining. The
     * router stores nothing for it and the bot does not take a turn over a
     * payload it has no words for.
     */
    public function decode(Request $request, ChannelConnection $connection): ?InboundMessage;

    /**
     * What to answer the provider with.
     *
     * Not always an empty 200. VK wants the literal string `ok` and treats
     * anything else as a failure to redeliver; during setup it wants a
     * confirmation string instead. WhatsApp answers its own verification
     * handshake with the challenge it sent. A single shared response would make
     * two of the four channels impossible to connect.
     */
    public function acknowledge(Request $request, ChannelConnection $connection): Response;
}
