<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * The one door every incoming message comes through.
 *
 * Widget, webhook, importer and test all arrive here, and that is deliberate.
 * The three rules below are the ones nobody notices until they are broken —
 * a doubled answer, a lost thread, an overwritten phone number — and each of
 * them is exactly one line of code, so each of them would otherwise be
 * re-decided per channel by whoever wrote it, differently and in good faith.
 */
class MessageRouter
{
    public function __construct(
        private readonly BotEngine $engine,
    ) {}

    /**
     * Records what was said and lets the bot answer it.
     *
     * Returns the stored message, or null when there was nothing to store. Null
     * covers a payload with no text at all — a sticker, a location, a channel's
     * own keep-alive — which must not open a thread or wake the bot.
     *
     * **The write and the bot's turn are deliberately not in one transaction.**
     * The bot sends things out of the building: a message to Telegram, a
     * capability that charges a card. A transaction wrapped around that would,
     * on any later failure, roll back the record of what was sent while the
     * customer keeps the message — leaving the journal saying it never happened
     * and the next turn repeating it.
     */
    public function receive(InboundMessage $inbound): ?Message
    {
        $body = trim((string) $inbound->body);

        if ($body === '') {
            return null;
        }

        $existing = $this->alreadyReceived($inbound);

        if ($existing !== null) {
            return $existing;
        }

        $stored = DB::transaction(function () use ($inbound, $body): ?array {
            $conversation = $this->conversation($inbound);

            $message = $this->store($conversation, $inbound, $body);

            if ($message === null) {
                return null;
            }

            $conversation->forceFill([
                'last_message_at' => now(),
                'last_incoming_at' => now(),
                'status' => ConversationStatus::Open,
            ])->save();

            return ['conversation' => $conversation, 'message' => $message];
        });

        if ($stored === null) {
            return $this->alreadyReceived($inbound);
        }

        $this->engine->handle($stored['conversation']->refresh(), $stored['message']);

        return $stored['message'];
    }

    /**
     * The same message arriving a second time.
     *
     * **Webhooks redeliver on a schedule.** A provider that did not get its 200
     * in time — because the bot was thinking, because a worker was busy — sends
     * the identical update again, and again after that. Without this check one
     * question from the customer becomes one stored message per attempt and one
     * full bot turn per attempt: three copies of the answer, three capability
     * calls, three of whatever the scenario does on the way.
     *
     * Answering with the message already stored, rather than with null, is what
     * lets a caller stay idempotent: the second webhook gets the same result as
     * the first and reports success, which is the only thing that makes the
     * provider stop retrying.
     */
    private function alreadyReceived(InboundMessage $inbound): ?Message
    {
        $externalId = trim((string) $inbound->externalMessageId);

        if ($externalId === '') {
            return null;
        }

        $conversation = $this->findConversation($inbound);

        if ($conversation === null) {
            return null;
        }

        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * Writes the row, treating a lost race as a duplicate rather than an error.
     *
     * Two copies of one webhook can be in flight at the same moment, and both
     * can pass the check above before either has inserted. The unique index on
     * (conversation, external id) is what actually decides; this turns its
     * complaint into the same answer the check would have given a second later.
     */
    private function store(Conversation $conversation, InboundMessage $inbound, string $body): ?Message
    {
        $externalId = trim((string) $inbound->externalMessageId);

        try {
            return Message::create([
                'conversation_id' => $conversation->getKey(),
                'direction' => MessageDirection::Incoming,
                'author' => MessageAuthor::Visitor,
                'body' => $body,
                'external_id' => $externalId === '' ? null : $externalId,
                'meta' => $inbound->meta === [] ? null : $inbound->meta,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Older Laravel drivers report a duplicate key as a plain QueryException.
     *
     * Matched on the SQLSTATE class rather than the driver's message, which is
     * translated on some MySQL builds and worded differently on SQLite and
     * Postgres. 23 is the integrity-constraint class in every one of them.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }

    /**
     * The thread this person is already in, or a new one.
     *
     * Looked up by the provider's own conversation id where there is one, and by
     * the visitor's hashed token where there is not. The site widget is the
     * second case: a browser has no account and no chat id, and the token is the
     * only thing tying today's tab to the thread it opened yesterday.
     */
    private function conversation(InboundMessage $inbound): Conversation
    {
        $conversation = $this->findConversation($inbound);

        if ($conversation === null) {
            $conversation = Conversation::create([
                'channel' => $inbound->channel,
                'external_id' => $this->externalId($inbound),
                'visitor_key' => $this->visitorKey($inbound),
                'bot_id' => $inbound->botId,
                'status' => ConversationStatus::Open,
            ]);
        }

        $this->mergeContacts($conversation, $inbound);

        return $conversation;
    }

    private function findConversation(InboundMessage $inbound): ?Conversation
    {
        $externalId = $this->externalId($inbound);

        if ($externalId !== null) {
            return Conversation::query()
                ->where('channel', $inbound->channel)
                ->where('external_id', $externalId)
                ->first();
        }

        $visitorKey = $this->visitorKey($inbound);

        if ($visitorKey === null) {
            return null;
        }

        return Conversation::query()
            ->where('channel', $inbound->channel)
            ->where('visitor_key', $visitorKey)
            ->latest('id')
            ->first();
    }

    /**
     * Adds what the channel told us, without discarding what we already knew.
     *
     * **A blank field is filled, a filled one is left alone.** Channels hand out
     * whatever they happen to have on this request: Telegram sends the profile
     * name on every update, the widget sends whatever is currently typed into
     * its form, and both are frequently emptier than the row. An operator who
     * corrected a misspelled name, or a visitor who gave a phone number in the
     * first message and none in the second, would otherwise watch it be replaced
     * by the provider's version — or by nothing — on the very next line they
     * wrote. Nothing here reports the loss, which is what makes it worth a rule:
     * the thread simply stops knowing how to call the person back.
     *
     * Correcting a known value stays possible; it is an edit an operator makes,
     * not something an inbound payload does on its own.
     */
    private function mergeContacts(Conversation $conversation, InboundMessage $inbound): void
    {
        $incoming = [
            'display_name' => $inbound->displayName,
            'username' => $inbound->username,
            'contact_phone' => $inbound->phone,
            'contact_email' => $inbound->email,
            'entry_page' => $inbound->entryPage,
            'visitor_key' => $this->visitorKey($inbound),
        ];

        $changes = [];

        foreach ($incoming as $column => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                continue;
            }

            if (filled($conversation->{$column})) {
                continue;
            }

            $changes[$column] = $value;
        }

        if ($inbound->botId !== null && $conversation->bot_id === null) {
            $changes['bot_id'] = $inbound->botId;
        }

        if ($changes === []) {
            return;
        }

        $conversation->forceFill($changes)->save();
    }

    private function externalId(InboundMessage $inbound): ?string
    {
        $externalId = trim((string) $inbound->externalConversationId);

        return $externalId === '' ? null : $externalId;
    }

    private function visitorKey(InboundMessage $inbound): ?string
    {
        $key = trim((string) $inbound->visitorKey);

        return $key === '' ? null : $key;
    }
}
