<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MB\FilamentChatbot\Models\Message;

/**
 * A message landed in a conversation somebody may be watching.
 *
 * Broadcast only while `chatbot.realtime.enabled` is on. With it off this class
 * is still constructed and still dispatched — it is an ordinary event that
 * anything may listen to — and simply never reaches a socket, which is what
 * keeps the two paths from becoming two behaviours.
 *
 * **The payload is an announcement, not the message.** It carries the id and
 * nothing a reader could not already fetch: whoever receives it asks the history
 * endpoint for what is new, and that endpoint applies the visitor token, the
 * origin allow-list and every other rule. Putting the body on the wire would be
 * a second way out of the same table with none of those rules attached — and
 * broadcasting is the one place where a mistake of that kind is delivered to
 * everybody at once.
 */
class MessageArrived implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Message $message) {}

    /**
     * The channel a visitor listens on.
     *
     * Named by the conversation's own key rather than by its id. The name is
     * handed to a script on a customer's page, where a sequential id is both an
     * invitation to try the neighbouring number and a running count of how many
     * conversations this installation has had.
     *
     * Private, so subscribing goes through the authorisation callback — see
     * `routes/channels.php` — and a visitor is admitted only to the thread their
     * own token owns.
     *
     * @return Channel|array<int, Channel>
     */
    public function broadcastOn(): Channel|array
    {
        $key = (string) $this->message->conversation?->visitor_key;

        return $key === '' ? [] : [new PrivateChannel('chatbot.conversation.'.$key)];
    }

    public function broadcastAs(): string
    {
        return 'message.arrived';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->getKey(),
            'incoming' => $this->message->isIncoming(),
        ];
    }

    /**
     * Whether this one goes out at all.
     *
     * A package that broadcast regardless would make every installation depend
     * on a broadcasting connection being configured — and the failure of a
     * missing one is an exception thrown while saving a message, which is to say
     * a visitor's message lost to a feature nobody switched on.
     */
    public function broadcastWhen(): bool
    {
        return (bool) config('chatbot.realtime.enabled', false)
            && $this->message->conversation !== null;
    }
}
