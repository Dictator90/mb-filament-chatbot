<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * One line of the exchange a model is shown, in the only four shapes there are.
 *
 * Roles are named for who said it rather than for a provider's spelling: the
 * same list has to be translatable into Anthropic's content blocks, into an
 * OpenAI message array and into whatever a host's own client wants. A list of
 * raw provider payloads would tie the memory of a conversation to the provider
 * that happened to be configured the day it was written.
 *
 * The fourth shape — a tool result — is a message and not a return value on
 * purpose. A model only knows what a tool answered because it reads it as part
 * of the exchange, so "the booking was created, number 10781" has to sit in the
 * history exactly where it happened. Hand it back any other way and the next
 * turn has the model asking for the same thing again.
 */
final class AiMessage
{
    public const VISITOR = 'user';

    public const BOT = 'assistant';

    public const TOOL = 'tool';

    /**
     * @param  list<AiToolCall>  $calls
     */
    private function __construct(
        public readonly string $role,
        public readonly string $text = '',
        public readonly array $calls = [],
        public readonly ?string $callId = null,
        public readonly bool $ok = true,
    ) {}

    public static function fromVisitor(string $text): self
    {
        return new self(self::VISITOR, trim($text));
    }

    public static function fromBot(string $text): self
    {
        return new self(self::BOT, trim($text));
    }

    /**
     * What the model asked for, kept in the history as the model's own turn.
     *
     * Providers require it: a tool result with no matching request above it is
     * a malformed exchange, refused with a message about ids rather than about
     * the conversation.
     *
     * @param  list<AiToolCall>  $calls
     */
    public static function asking(array $calls, string $text = ''): self
    {
        return new self(self::BOT, trim($text), array_values($calls));
    }

    public static function result(string $callId, string $text, bool $ok = true): self
    {
        return new self(self::TOOL, trim($text), [], $callId, $ok);
    }

    public function hasCalls(): bool
    {
        return $this->calls !== [];
    }

    public function isToolResult(): bool
    {
        return $this->role === self::TOOL;
    }
}
