<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\ChannelConnection;

/**
 * A channel that can be asked for its waiting messages instead of being told.
 *
 * Written for networks where the provider cannot reach this server at all. A
 * webhook needs the connection to be possible in the provider's direction, and
 * on a Russian host that is exactly what is missing for Telegram: the address
 * is registered, the provider resolves it, and every delivery dies as
 * `Connection timed out` while nothing arrives in the logs. A proxy does not
 * help, because a proxy of ours is only in the path of calls *we* make.
 *
 * Polling turns the direction around: the same update, fetched by an outgoing
 * call that does go through. It is not the better way in general — it costs a
 * process that must keep running, and an answer waits as long as the pull does
 * — which is why it sits beside the webhook as a per-connection choice rather
 * than replacing it.
 *
 * **The cursor belongs to the connection, not to the process.** Restarting the
 * poller, running it on another host after a move, or crashing mid-batch must
 * not re-answer a week of conversations, and a cursor held in memory would do
 * exactly that on every deploy.
 */
interface ChannelPolling
{
    /**
     * Fetches whatever has arrived since the cursor.
     *
     * Implementations are expected to wait up to `$waitSeconds` for something to
     * appear — long polling rather than a poll every second. An immediate empty
     * answer is correct but turns the command into a hot loop against somebody
     * else's rate limit.
     *
     * The returned cursor is saved only after every message in the batch has
     * been handed on. That makes delivery at-least-once: an interrupted pass
     * fetches the same updates again, and the router drops them as already
     * received. The reverse order would make it at-most-once, and the thing
     * lost would be a customer's question.
     */
    public function pull(ChannelConnection $connection, ?string $cursor, int $waitSeconds): PollBatch;
}
