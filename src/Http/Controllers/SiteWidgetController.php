<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MB\FilamentChatbot\Channels\Drivers\SiteDriver;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * What the browser widget talks to.
 *
 * Four small endpoints and one rule underneath all of them: the visitor token
 * decides which conversation the caller may see, and nothing else does. No
 * conversation id is ever accepted from the request — a widget that could name
 * its own thread is a widget that can name somebody else's, and the read that
 * follows is indistinguishable from a legitimate one afterwards.
 *
 * Every route here runs without CSRF protection and without a session, because
 * it is called from another domain by a script tag. `WidgetOrigin` is what
 * stands in for both — see the reasoning there.
 */
class SiteWidgetController
{
    public function __construct(
        private readonly MessageRouter $router,
    ) {}

    /**
     * The transcript since the id the widget already has.
     *
     * `after` rather than a page number: the widget polls, and what it needs is
     * "anything new", which a page number cannot express once a message has
     * arrived between two polls.
     *
     * A caller with no token, or with one that matches no thread, gets an empty
     * transcript and no new token. Polling must not be able to create anything:
     * a widget sitting unopened on a busy page polls all day, and issuing a
     * conversation for each would fill the desk with threads nobody wrote in.
     */
    public function history(Request $request): JsonResponse
    {
        $conversation = $this->conversation($request);

        if ($conversation === null) {
            return response()->json([
                'messages' => [],
                'choices' => [],
                'labels' => $this->labels(),
            ]);
        }

        $after = max(0, (int) $request->query('after', 0));

        $messages = $conversation->messages()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit((int) config('chatbot.chat.history_limit', 50))
            ->get();

        return response()->json([
            'conversation' => $conversation->getKey(),
            'status' => $conversation->status->value,
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->getKey(),
                'body' => (string) $message->body,
                'incoming' => $message->isIncoming(),
                'author' => $message->author->value,
                'at' => $message->created_at?->toIso8601String(),
            ])->all(),
            'choices' => $this->choices($conversation),
            'labels' => $this->labels(),
        ]);
    }

    /**
     * The wording of the widget's own chrome, in this application's language.
     *
     * Sent with the transcript rather than baked into `widget.js`, and that is
     * forced rather than preferred. The script is one static file behind an
     * ETag and a shared cache, byte-identical for every site and every visitor;
     * a locale compiled into it would be handed to the second caller in
     * whichever language the first one happened to warm the cache with. This
     * endpoint already answers per request, so the labels cost nothing extra
     * and are always the language the application is currently speaking.
     *
     * Returned on the empty branch too, because that is the branch a fresh
     * visitor takes: the widget polls before anybody has typed, and labels
     * withheld until a conversation exists would leave the launcher in the
     * script's own fallback wording until the first message was sent.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'title' => __('chatbot::widget.title'),
            'launcher' => __('chatbot::widget.launcher'),
            'placeholder' => __('chatbot::widget.placeholder'),
            'send' => __('chatbot::widget.send'),
            'close' => __('chatbot::widget.close'),
            'failed' => __('chatbot::widget.failed'),
        ];
    }

    /**
     * Records what the visitor typed and lets the bot answer.
     *
     * The token is issued here and only here, on the first message. Returned in
     * the body once; the server keeps the hash, so this response is the only
     * time it can be handed over, and a widget that loses it starts a new
     * conversation rather than resuming a stranger's.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('chatbot.chat.max_message_length', 4000)],
            'bot' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'string', 'max:2048'],
            'identity' => ['nullable', 'array'],
            'identity.id' => ['nullable', 'string', 'max:191'],
            'identity.signature' => ['nullable', 'string', 'max:191'],
            'identity.name' => ['nullable', 'string', 'max:191'],
            'identity.phone' => ['nullable', 'string', 'max:32'],
            'identity.email' => ['nullable', 'string', 'max:191'],
        ]);

        $token = VisitorToken::fromRequest($request);
        $issued = $token === null;
        $token ??= VisitorToken::issue();

        $identity = $this->verifiedIdentity($data['identity'] ?? null);

        $message = $this->router->receive(new InboundMessage(
            channel: SiteDriver::KEY,
            body: $data['body'],
            displayName: $identity['name'] ?? null,
            phone: $identity['phone'] ?? null,
            email: $identity['email'] ?? null,
            entryPage: $data['page'] ?? null,
            visitorKey: VisitorToken::hash($token),
            botId: $this->botId($data['bot'] ?? null),
        ));

        return response()->json(array_filter([
            'token' => $issued ? $token : null,
            'id' => $message?->getKey(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The visitor has seen everything up to now.
     *
     * Stamped with the current moment rather than with the id of the last
     * message the widget listed, for the same reason the operator's own read
     * mark is: a message can arrive between the widget rendering and this call,
     * and copying an id would mark that one read as well.
     */
    public function read(Request $request): JsonResponse
    {
        $conversation = $this->conversation($request);

        $conversation?->forceFill(['visitor_read_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * The visitor is typing.
     *
     * **Nothing is written down.** Typing is true for a second or two and then
     * stops being true without anyone telling us — the tab is closed, the phone
     * locks, the network drops — so a stored flag has no reliable moment at
     * which it becomes false, and the desk would show people typing who left
     * hours ago. The endpoint exists as the seam a host broadcasts from, where
     * the signal is as short-lived as the fact it reports.
     */
    public function typing(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => $this->conversation($request) !== null,
        ]);
    }

    /**
     * The CORS preflight.
     *
     * `WidgetOrigin` answers OPTIONS itself and never reaches this, so the
     * method is a target for the route to name rather than logic — a closure
     * there would be uncacheable, and `route:cache` refusing to run is a much
     * worse way to learn about it than reading this.
     */
    public function preflight(): JsonResponse
    {
        return response()->json([], 204);
    }

    /**
     * The thread this token owns, or null.
     *
     * The lookup is by the hash of the token, over the site channel only. A
     * token from one channel can therefore never address a conversation on
     * another, even if a value somehow collided.
     */
    private function conversation(Request $request): ?Conversation
    {
        $token = VisitorToken::fromRequest($request);

        if ($token === null) {
            return null;
        }

        return Conversation::query()
            ->where('channel', SiteDriver::KEY)
            ->where('visitor_key', VisitorToken::hash($token))
            ->latest('id')
            ->first();
    }

    /**
     * The options offered by the last thing the bot said.
     *
     * Read from the latest outgoing message rather than accumulated, because
     * options belong to one question: a visitor who is now being asked for their
     * phone number must not still see the buttons from the question before it.
     *
     * @return list<array{label: string}>
     */
    private function choices(Conversation $conversation): array
    {
        return $conversation->pendingChoices();
    }

    /**
     * The contact details the embedding page vouched for, or none.
     *
     * **An unsigned identity is ignored entirely, and so is one whose signature
     * does not check out.** Everything in it arrives from a script running on a
     * page this application does not control, where the visitor can edit it
     * before it is sent; taken at face value, "who this is" would be whatever
     * the visitor typed into their own console. The host signs the id with the
     * shared secret on its own server, which is the only part of the round trip
     * the visitor cannot reach.
     *
     * With no secret configured there is nothing to check against, so nothing is
     * accepted — the same direction as the empty origin list, and for the same
     * reason.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array<string, string>
     */
    private function verifiedIdentity(?array $identity): array
    {
        if ($identity === null) {
            return [];
        }

        $secret = trim((string) config('chatbot.widget.identity_secret', ''));
        $id = trim((string) ($identity['id'] ?? ''));
        $signature = trim((string) ($identity['signature'] ?? ''));

        if ($secret === '' || $id === '' || $signature === '') {
            return [];
        }

        if (! hash_equals(hash_hmac('sha256', $id, $secret), $signature)) {
            return [];
        }

        return array_filter([
            'name' => trim((string) ($identity['name'] ?? '')),
            'phone' => trim((string) ($identity['phone'] ?? '')),
            'email' => trim((string) ($identity['email'] ?? '')),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * The bot named by the embed, looked up by slug.
     *
     * By slug and not by id, because the value is written into a script tag on
     * a public page: an id there is both meaningless to whoever maintains that
     * page and a number anybody can change to point at a different bot. An
     * unknown slug resolves to nothing rather than failing, so a typo in an
     * embed leaves the conversation on the default routing instead of dropping
     * the visitor's message.
     */
    private function botId(?string $slug): ?int
    {
        $bot = $this->publicBot($slug);

        return $bot === null ? null : (int) $bot->getKey();
    }

    /**
     * The named bot, but only if it is allowed to answer this request.
     *
     * Two settings decide that, and until now the runtime read neither. Both sat
     * in the bot's form, both were saved, and both were ignored — which is worse
     * than not offering them: somebody unticks "answers on the site", sees the
     * form save, and believes the bot is off their pages while it goes on
     * answering every visitor.
     *
     * `is_public_widget` is a straight refusal. `allowed_origins` narrows the
     * installation-wide list rather than replacing it: the global list is the
     * guard that fails closed, and this one says which of those sites *this*
     * bot belongs on. An empty list therefore means "no narrowing" and not
     * "nobody" — every bot has one until somebody fills it in, and reading it
     * the other way would silence every widget on the first upgrade.
     *
     * Refusing here rather than in the origin middleware is not a preference:
     * the middleware runs before the body is read, and which bot was asked for
     * is in the body.
     */
    private function publicBot(?string $slug): ?Bot
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        $bot = Bot::query()->where('slug', $slug)->first();

        if ($bot === null || $bot->is_public_widget !== true) {
            return null;
        }

        return $this->reachesBot($bot) ? $bot : null;
    }

    private function reachesBot(Bot $bot): bool
    {
        $origins = array_values(array_filter(array_map(
            static fn (mixed $origin): string => trim((string) $origin),
            (array) $bot->allowed_origins,
        ), static fn (string $origin): bool => $origin !== ''));

        if ($origins === []) {
            return true;
        }

        $origin = trim((string) request()->headers->get('Origin', ''));

        /*
         * No Origin header means a same-origin request from this application's
         * own pages — the middleware has already established that much. A bot
         * that named its sites has not named this one, so it does not answer
         * here either.
         */
        return $origin !== '' && in_array($origin, $origins, true);
    }
}
