<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Finds the account a message should be answered from.
 *
 * Drivers are registered once per channel and shared by every bot, so they
 * cannot hold credentials of their own. This is where a driver asks which
 * account the thread in front of it belongs to, and it is deliberately the only
 * place that knows: a second lookup written inside a driver would be a second
 * answer to "whose token", and the two would disagree the first time a bot was
 * reassigned.
 *
 * Lookups are memoised per request. One outgoing message asks for the same
 * connection two or three times — the driver checks it is configured, then
 * builds the request, then writes the result — and a conversation page that
 * renders twenty threads would otherwise issue twenty identical queries.
 */
class ChannelConnections
{
    /** @var array<string, ChannelConnection|null> */
    private array $memo = [];

    /**
     * The account this thread is answered from, or nothing.
     *
     * Nothing is a real answer and not an error: a thread whose bot was deleted,
     * or one that arrived before the messenger was wired up, still exists and
     * still shows its transcript. The driver turns it into a readable delivery
     * failure, which is the one place a person can act on it.
     */
    public function forConversation(Conversation $conversation): ?ChannelConnection
    {
        $botId = $conversation->bot_id;
        $channel = trim((string) $conversation->channel);

        if ($botId === null || $channel === '') {
            return null;
        }

        return $this->forBot((int) $botId, $channel);
    }

    public function forBot(int $botId, string $channel): ?ChannelConnection
    {
        return $this->memo['bot:'.$botId.':'.$channel] ??= ChannelConnection::query()
            ->where('bot_id', $botId)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * The connection a provider's webhook address points at.
     *
     * The channel is checked alongside the identifier rather than trusted from
     * it. The segment is random, so a collision across channels is improbable
     * — but "improbable" is not the same as "cannot", and the cost of being
     * wrong is a Telegram update decoded as a VK event and written into the
     * wrong customer's thread.
     *
     * Inactive connections are excluded here, at the door. A switched-off
     * messenger that still accepted updates would keep opening conversations
     * nobody is watching, and the switch would look broken rather than off.
     */
    public function byPublicId(string $channel, string $publicId): ?ChannelConnection
    {
        $publicId = trim($publicId);

        if ($publicId === '') {
            return null;
        }

        return $this->memo['public:'.$channel.':'.$publicId] ??= ChannelConnection::query()
            ->where('channel', $channel)
            ->where('public_id', $publicId)
            ->active()
            ->first();
    }

    /**
     * Forgets what was looked up, for the one caller that changes it.
     *
     * The settings screen saves a connection and immediately asks the driver to
     * check it against the provider. Without this, that check would run against
     * the credentials as they were before the save — and report the old token's
     * verdict about the new one.
     */
    public function forget(): void
    {
        $this->memo = [];
    }
}
