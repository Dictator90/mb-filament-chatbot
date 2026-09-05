<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * The one way this package talks to a language model.
 *
 * Three methods, and the small surface is the design. Everything the package
 * asks a model to do — answer from the agency's own material, pull a few
 * values out of a sentence, choose between scenarios — is one question with one
 * answer, and a contract shaped like that can be satisfied by an HTTP endpoint,
 * by laravel/ai, by a host's own client, or by nothing at all. Model streaming,
 * tool calling and conversation state were all left out deliberately: each of
 * them would tie the package to one provider's idea of a session, and a driver
 * that cannot express it would have to fake it.
 *
 * **A gateway never throws.** Not for a missing key, not for a refused request,
 * not for a socket that closed halfway. Every one of those comes back as
 * {@see AiReply::declined()} with an {@see AiFailure} on it, because on the
 * other side of this call is a node that has a second exit drawn on the canvas
 * and a scenario that has to keep talking. An exception here would leave the
 * visitor's turn ending in nothing.
 */
interface AiGateway
{
    /** The driver name this gateway was built under, for the run journal. */
    public function name(): string;

    /**
     * Whether asking is worth attempting at all.
     *
     * Answered from settings alone — a key, an address, an installed class —
     * and never by calling the provider. A liveness check would put a network
     * round trip in front of every node that might use a model, including the
     * ones that end up not needing it.
     */
    public function isAvailable(): bool;

    public function ask(AiPrompt $prompt): AiReply;
}
