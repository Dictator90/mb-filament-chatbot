<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

/**
 * Something a person said, in the shape the router understands.
 *
 * Every channel decodes its own payload into this and stops there. Without it
 * each channel would reach into the models directly, and the rules that must
 * hold for all of them — find the thread or open it, refuse a redelivered
 * message, never overwrite a contact detail we already have — would exist once
 * per channel, in slightly different versions, all of them believed correct.
 *
 * Nothing here is trusted. The display name, the phone and the e-mail are
 * whatever the visitor or the provider claimed; they are recorded as claims and
 * never used to decide who this person is. Proving identity is a separate act
 * that stamps `identified_at`.
 */
final class InboundMessage
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?string $externalConversationId = null,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $body = null,
        public readonly ?string $displayName = null,
        public readonly ?string $username = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $entryPage = null,
        /**
         * The sha256 of the visitor's widget token, never the token.
         *
         * Hashing happens at the edge that received the token, so that the raw
         * credential never travels further into the application than the
         * request that carried it — and cannot be written to a log by anything
         * that dumps this object.
         */
        public readonly ?string $visitorKey = null,
        public readonly ?int $botId = null,
        /**
         * Whether the channel proved who this is, rather than being told.
         *
         * The one thing on this object that is not a claim, and separate from
         * the contact fields for exactly that reason: a phone number is a phone
         * number whether it was signed or typed, and only this says which. A
         * channel with nothing to prove it with leaves it false, which is the
         * honest answer for every public chat box there is.
         */
        public readonly bool $identified = false,
        public readonly array $meta = [],
    ) {}
}
