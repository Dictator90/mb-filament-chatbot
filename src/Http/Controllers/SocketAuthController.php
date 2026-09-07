<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MB\FilamentChatbot\Http\VisitorToken;

/**
 * Lets a widget subscribe to its own conversation and to nothing else.
 *
 * Laravel's own `/broadcasting/auth` answers for an authenticated user, and a
 * visitor is not one: they have a token this package issued, no session and no
 * cookie that survives being on somebody else's domain. So the package
 * authorises its own channel, on its own guarded surface — behind the origin
 * allow-list and the same token every other widget endpoint reads.
 *
 * **The channel name is checked against the token, never trusted from the
 * request.** A subscription request names the channel it wants; taken at face
 * value that is a reader asking to be sent somebody else's conversation, live,
 * for as long as it lasts. What is compared is the hash of the presented token
 * against the name asked for, so the only channel anybody can be admitted to is
 * the one their own token already opens through the history endpoint.
 */
class SocketAuthController
{
    public const PREFIX = 'private-chatbot.conversation.';

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('chatbot.realtime.enabled', false)) {
            return response()->json(['message' => 'Live updates are switched off.'], 403);
        }

        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:191'],
            'channel_name' => ['required', 'string', 'max:191'],
        ]);

        $token = VisitorToken::fromRequest($request);

        if ($token === null) {
            return response()->json(['message' => 'This request carries no visitor token.'], 403);
        }

        if ($data['channel_name'] !== self::PREFIX.VisitorToken::hash($token)) {
            return response()->json(['message' => 'That channel does not belong to this visitor.'], 403);
        }

        $connection = $this->connection();
        $secret = (string) config("broadcasting.connections.{$connection}.secret");
        $key = (string) config("broadcasting.connections.{$connection}.key");

        if ($secret === '' || $key === '') {
            return response()->json(['message' => 'Live updates are not configured.'], 503);
        }

        /*
         * The Pusher signature, which Reverb speaks too. Computed here rather
         * than reached for through a client library: the package holds no
         * broadcasting dependency, and this is the whole of what the protocol
         * asks for at this point.
         */
        $signature = hash_hmac('sha256', $data['socket_id'].':'.$data['channel_name'], $secret);

        return response()->json(['auth' => $key.':'.$signature]);
    }

    /**
     * The broadcasting connection this installation broadcasts on.
     *
     * Named in the package's own config but resolved in the host's, so the
     * credentials live in one place. A package holding a second copy of a
     * secret is a package that has to be told when it is rotated.
     */
    private function connection(): string
    {
        $named = (string) config('chatbot.realtime.driver', '');

        return $named !== '' ? $named : (string) config('broadcasting.default');
    }
}
