<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use MB\FilamentChatbot\Bot\Conditions\Fields\CookieCondition;
use MB\FilamentChatbot\Channels\Drivers\SiteDriver;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Channels\Outbox;
use MB\FilamentChatbot\Channels\WidgetFeatures;
use MB\FilamentChatbot\Channels\WidgetIdentity;
use MB\FilamentChatbot\Channels\WidgetResolver;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Chat\AttachmentRefused;
use MB\FilamentChatbot\Chat\AttachmentStore;
use MB\FilamentChatbot\Http\AttachmentResponse;
use MB\FilamentChatbot\Http\VisitorToken;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly WidgetResolver $widgets,
        private readonly Outbox $outbox,
    ) {}

    /**
     * How this bot's widget should look.
     *
     * Answers for a bot that will not talk — one whose widget is switched off,
     * or that this site is not listed on — with the package defaults rather than
     * with a refusal. The visitor gets a launcher that opens onto the ordinary
     * "this is not available" path instead of an unstyled box in the corner of
     * the page, and whoever is debugging the embed learns which bot is missing
     * from the endpoint that actually carries the conversation.
     *
     * Nothing here is private: it is colours and corner radii, and they are on
     * public display the moment the widget draws itself.
     */
    public function theme(Request $request): JsonResponse
    {
        $widget = $this->widgets->forRequest($request);

        if (! $this->widgets->reachable($widget, $request)) {
            $widget = null;
        }

        $theme = WidgetTheme::forWidget($widget);

        return response()->json([
            'properties' => $theme->properties(),
            'flags' => $theme->flags(),
            /*
             * What this placement lets a visitor do, beside what it looks like
             * rather than inside it. The widget draws its emoji button and its
             * paper clip from this, so a widget that will not talk here — one
             * switched off, or not listed on this site — falls back to the
             * shipped answers, and those have attachments off. A launcher that
             * offers a paper clip and then refuses the file is worse than one
             * that never offered it.
             */
            'features' => WidgetFeatures::forWidget($widget)->toArray(),
            'labels' => array_filter((array) ($widget?->labels ?? [])),
            /*
             * The cookie names this placement was told it may read, and no
             * others. The script could read every cookie on the page it sits
             * on; what makes it acceptable that it reads any is that it is told
             * which by the person who set the placement up, and that the same
             * list is checked again when the answer comes back.
             *
             * Empty for a widget that cannot talk here, like everything else on
             * this response: a script this installation will not answer has no
             * business collecting anything for it.
             */
            'cookies' => $widget?->cookieNames() ?? [],
        ]);
    }

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

        /*
         * The files are eager-loaded rather than asked for per line. Almost
         * every message has none, so the relation is one extra query for the
         * whole poll and would otherwise be fifty.
         */
        $messages = $conversation->messages()
            ->with('attachments')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit((int) config('chatbot.chat.history_limit', 50))
            ->get();

        /*
         * Handing the transcript over *is* the delivery on this channel, so the
         * stamp goes on here and nowhere else. It is written once per message —
         * see the note on `confirmFetched` — and never on a poll that found
         * nothing, which is nearly all of them.
         */
        $this->outbox->confirmFetched($messages);

        return response()->json([
            'conversation' => $conversation->getKey(),
            'status' => $conversation->status->value,
            /*
             * How far the operator has read, as one moment rather than as a flag
             * on every message.
             *
             * The widget only ever asks for what is newer than the last id it
             * holds, so a per-message stage would be correct once and then frozen
             * — a visitor's message would show two ticks for the rest of the
             * conversation however long ago somebody read it. A watermark
             * re-answers the question for every bubble already on screen, and it
             * is the same value the desk's own unread badge is computed from.
             */
            'read_through' => $conversation->agent_read_at?->toIso8601String(),
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->getKey(),
                'body' => (string) $message->body,
                'incoming' => $message->isIncoming(),
                'author' => $message->author->value,
                'at' => $message->created_at?->toIso8601String(),
                'attachments' => $this->attachments($message),
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
            'emoji' => __('chatbot::widget.emoji'),
            'attach' => __('chatbot::widget.attach'),
            'remove' => __('chatbot::widget.remove'),
            /*
             * The three stages, named. A tick is a convention people read at a
             * glance and nothing whatsoever to a screen reader, so the mark the
             * widget draws carries one of these as its accessible name and would
             * otherwise be an unlabelled character in the corner of a bubble.
             */
            'receipt_sent' => __('chatbot::widget.receipt_sent'),
            'receipt_delivered' => __('chatbot::widget.receipt_delivered'),
            'receipt_read' => __('chatbot::widget.receipt_read'),
            'expand' => __('chatbot::widget.expand'),
        ];
    }

    /**
     * Records what the visitor typed and lets the bot answer.
     *
     * The token is issued here and only here, on the first message. Returned in
     * the body once; the server keeps the hash, so this response is the only
     * time it can be handed over, and a widget that loses it starts a new
     * conversation rather than resuming a stranger's.
     *
     * **A file arrives with the message and not through an endpoint of its own.**
     * An upload that stood alone would have to be owned by somebody before there
     * was a message to own it — a row belonging to a token, waiting for a send
     * that may never come, and sweepable only by a schedule a package cannot
     * assume the host runs. Arriving together, the file is refused or stored in
     * the same request that decides whether there is a conversation at all, and
     * it inherits this endpoint's origin check and its send limiter without
     * either being written twice.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            /*
             * Required only when nothing is attached. A photograph of a broken
             * parcel with no sentence under it is an ordinary message, and
             * refusing it would send the visitor looking for something to type.
             */
            'body' => [
                'required_without:attachment',
                'nullable',
                'string',
                'max:'.(int) config('chatbot.chat.max_message_length', 4000),
            ],
            'attachment' => ['nullable', 'file'],
            'bot' => ['nullable', 'string', 'max:191'],
            'widget' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'string', 'max:2048'],
            'cookies' => ['nullable', 'array', 'max:20'],
            'cookies.*' => ['nullable', 'string', 'max:255'],
            'identity' => ['nullable', 'array'],
            'identity.id' => ['nullable', 'string', 'max:191'],
            'identity.signature' => ['nullable', 'string', 'max:191'],
            'identity.name' => ['nullable', 'string', 'max:191'],
            'identity.phone' => ['nullable', 'string', 'max:32'],
            'identity.email' => ['nullable', 'string', 'max:191'],
        ]);

        $file = $request->file('attachment');

        if ($file instanceof UploadedFile) {
            $this->refuseUnacceptable($request, $file);
        }

        $token = VisitorToken::fromRequest($request);
        $issued = $token === null;
        $token ??= VisitorToken::issue();

        $identity = $this->verifiedIdentity($data['identity'] ?? null);

        $message = $this->router->receive(new InboundMessage(
            channel: SiteDriver::KEY,
            body: $this->body($data['body'] ?? null, $file),
            displayName: $identity['name'] ?? null,
            phone: $identity['phone'] ?? null,
            email: $identity['email'] ?? null,
            entryPage: $data['page'] ?? null,
            visitorKey: VisitorToken::hash($token),
            botId: $this->answeringBotId($request),
            /*
             * A checked signature is the one act that proves who this is, so it
             * is the one place the flag may be raised. It used to be raised
             * nowhere at all: the identity verified, the name and phone were
             * recorded, and the condition the package calls the gate in front of
             * anything personal stayed shut for the only visitors entitled to
             * pass it.
             */
            identified: $identity !== [],
            meta: $this->declaredCookies($request, $data['cookies'] ?? null),
        ));

        if ($file instanceof UploadedFile && $message instanceof Message) {
            app(AttachmentStore::class)->store($message, $file);
        }

        return response()->json(array_filter([
            'token' => $issued ? $token : null,
            'id' => $message?->getKey(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Hands back one attached file, to the one conversation that owns it.
     *
     * The request names a file and never a conversation, exactly as everything
     * else here does: which thread the caller may read is decided by their
     * token, and the file is then required to be in that thread. A file
     * belonging to somebody else is a 404 rather than a 403 — a refusal would
     * confirm that the id exists, which is the only thing a stranger holding one
     * still needs to learn.
     *
     * The bytes go out under the type detected when they arrived, with sniffing
     * switched off, and only pictures are shown in place. Everything else is a
     * download, because a document rendered inline runs with this application's
     * own origin in the address bar.
     */
    public function attachment(Request $request, string $attachment): StreamedResponse|BinaryFileResponse
    {
        $conversation = $this->conversation($request);

        abort_if($conversation === null, 404);

        $file = Attachment::query()
            ->where('uuid', $attachment)
            ->where('conversation_id', $conversation->getKey())
            ->first();

        abort_if($file === null, 404);

        return AttachmentResponse::for($file);
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
     * Stops a file this widget must not take, before anything is written.
     *
     * Two separate refusals, in this order. The first is whether this placement
     * accepts files at all — a widget that does not is not merely one whose
     * button is hidden, because the button is drawn by a script on a page the
     * visitor can edit, and a check that lived only there would be no check.
     * The second is whether the file itself passes, which `AttachmentStore`
     * answers by looking at the bytes.
     *
     * Reported as a validation error rather than a 403 so the widget can put the
     * sentence under its own composer, where the person who chose the file is
     * looking.
     *
     * @throws ValidationException
     */
    private function refuseUnacceptable(Request $request, UploadedFile $file): void
    {
        $widget = $this->widgets->forRequest($request);

        if (! $this->widgets->reachable($widget, $request)) {
            $widget = null;
        }

        if (! WidgetFeatures::forWidget($widget)->attachments()) {
            throw ValidationException::withMessages([
                'attachment' => __('chatbot::widget.attachment_off'),
            ]);
        }

        $store = app(AttachmentStore::class);
        $refusal = $store->refusalFor($file);

        if ($refusal === null) {
            return;
        }

        throw ValidationException::withMessages([
            'attachment' => $refusal->reason === AttachmentRefused::TOO_LARGE
                ? __('chatbot::widget.attachment_too_large', ['limit' => $store->maxKilobytes()])
                : __('chatbot::widget.attachment_wrong_type'),
        ]);
    }

    /**
     * What the transcript records for a message that is only a file.
     *
     * `MessageRouter` refuses an empty body outright — a message with nothing in
     * it must not open a thread or wake the bot — so a file sent on its own is
     * filed under the name it arrived with. A placeholder sentence would have
     * been better English and worse data: it would write one language into a row
     * that every channel, every export and every operator reads afterwards,
     * whichever language the visitor was speaking.
     */
    private function body(?string $body, ?UploadedFile $file): string
    {
        $body = trim((string) $body);

        if ($body !== '' || $file === null) {
            return $body;
        }

        return trim((string) $file->getClientOriginalName()) ?: 'file';
    }

    /**
     * The files on one message, as the widget needs them.
     *
     * The address is the file's own random id and carries nothing about the
     * conversation, because the endpoint behind it works out the conversation
     * from the token. A URL that named the thread would be a URL somebody could
     * edit.
     *
     * @return list<array{id: string, name: string, mime: string, size: int, image: bool, url: string}>
     */
    private function attachments(Message $message): array
    {
        return $message->attachments
            ->map(fn (Attachment $file): array => [
                'id' => (string) $file->uuid,
                'name' => (string) $file->name,
                'mime' => (string) $file->mime,
                'size' => (int) $file->size,
                'image' => $file->isImage(),
                'url' => route('chatbot.site.attachment', ['attachment' => $file->uuid]),
            ])
            ->values()
            ->all();
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
     * the visitor typed into their own console. The host signs on its own
     * server, which is the only part of the round trip the visitor cannot
     * reach.
     *
     * With no secret configured there is nothing to check against, so nothing is
     * accepted — the same direction as the empty origin list, and for the same
     * reason.
     *
     * The checking itself lives in {@see WidgetIdentity}, because the host's own
     * site computes the same signature and one description of it is the only way
     * the two stay in step.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>
     */
    private function verifiedIdentity(?array $identity): array
    {
        return WidgetIdentity::verify($identity) ?? [];
    }

    /**
     * The cookies this placement was allowed to read, kept — and everything
     * else the request offered, dropped.
     *
     * **The list is checked here as well as sent out, and the second check is
     * the one that matters.** What the widget was told to read is a hint to a
     * script running on a page this application does not control; what arrives
     * is whatever the caller decided to put in the field. Trusting it would
     * mean any page could write any name into a conversation's context and any
     * scenario reading a cookie condition would be reading a value the visitor
     * chose.
     *
     * They are still not trustworthy after the check — a cookie is a value the
     * browser held and the page could set, so a condition on one may steer a
     * conversation and must never open anything
     * ({@see CookieCondition}). What
     * the check buys is a bounded, declared set of names instead of an open
     * channel into the record.
     *
     * @param  array<string, mixed>|null  $sent
     * @return array<string, mixed>
     */
    private function declaredCookies(Request $request, ?array $sent): array
    {
        $widget = $this->widgets->forRequest($request);

        if ($sent === null || $sent === [] || ! $this->widgets->reachable($widget, $request)) {
            return [];
        }

        $allowed = $widget?->cookieNames() ?? [];
        $kept = [];

        foreach ($allowed as $name) {
            $value = $sent[$name] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $kept[$name] = trim($value);
            }
        }

        return $kept === [] ? [] : ['cookies' => $kept];
    }

    /**
     * Which bot answers this message, if any.
     *
     * Three things have to be true, and each of them silently used to be
     * assumed: the request names a widget that exists and is switched on, that
     * widget belongs on the site the request came from, and the bot behind it is
     * itself active.
     *
     * A widget that fails any of them does not refuse the message — it takes the
     * bot off it. The conversation still opens and goes to a person, which is
     * what "no bot" has always meant here. Refusing instead would drop what a
     * visitor typed on the strength of a configuration mistake they had no part
     * in, and they would be looking at a chat window that swallowed their
     * question.
     */
    private function answeringBotId(Request $request): ?int
    {
        $widget = $this->widgets->forRequest($request);

        if (! $this->widgets->reachable($widget, $request)) {
            return null;
        }

        return $widget?->answeringBotId();
    }
}
