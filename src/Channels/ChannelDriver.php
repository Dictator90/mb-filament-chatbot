<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * One way of reaching a person.
 *
 * An interface rather than a base class, because the two channels that ship
 * with the package share nothing at all: the site widget delivers by doing
 * nothing and letting the browser ask, Telegram delivers by calling somebody
 * else's HTTP API. A shared parent would have to be empty, and an empty parent
 * that hosts are told to extend is a promise the package cannot keep once a
 * third channel arrives with a third shape.
 *
 * Drivers are registered by the host into the registry, keyed by `key()`. That
 * key is also what lands in `chatbot_conversations.channel`, which is why it is
 * a plain string on both sides: a helpdesk adding WhatsApp must not have to
 * fork the package to widen an enum.
 */
interface ChannelDriver
{
    /**
     * The stable key this channel is stored under, e.g. 'site', 'telegram'.
     *
     * Written into every conversation row it opens, so it may never change once
     * an installation has traffic: renaming it orphans every thread that came
     * in through it, and orphaned threads cannot be answered.
     */
    public function key(): string;

    /**
     * What to call the channel on screen.
     */
    public function label(): string;

    /**
     * Whether this channel could actually deliver right now.
     *
     * Separate from being registered, because the two fail differently. An
     * unregistered channel is a mistake in the host's provider; a registered one
     * without credentials is an installation that has not finished being set up,
     * and it must be able to say so on the settings screen instead of only in a
     * failed message hours later.
     */
    public function isConfigured(): bool;

    /**
     * Hands one already-recorded message to the provider.
     *
     * The message exists before this is called and keeps existing whatever this
     * returns. Delivery is a property of the row, not a precondition for it:
     * the transcript is what was said, and a provider outage must not be able
     * to erase a sentence the bot already committed to.
     */
    public function send(Conversation $conversation, Message $message): DeliveryResult;

    /**
     * Whether the channel can draw the options as tappable buttons.
     *
     * False makes the outgoing text carry the options as a numbered list
     * instead — see `ChannelText`. The engine already accepts a bare number as
     * an answer, so a channel that says false here stays fully usable rather
     * than becoming a place where every question is unanswerable.
     */
    public function supportsButtons(): bool;
}
