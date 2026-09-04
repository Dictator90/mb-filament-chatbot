<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Tests\Support;

use MB\FilamentChatbot\Bot\Runtime;

/**
 * A runtime that keeps what a node tried to do instead of doing it.
 *
 * Node kinds are tested through this rather than through the engine on purpose.
 * Driven by the engine, a node's own mistake arrives disguised: an option that
 * never matched and a transition the walker resolved wrongly both look like a
 * conversation ending in the wrong place, and the test that catches one of them
 * would have to build a whole scenario, a bot and a conversation to say so. Here
 * the node is called directly and answers for itself.
 */
final class RecordingRuntime implements Runtime
{
    /** @var list<string> */
    public array $said = [];

    /** @var array<string, string|null> */
    public array $stored = [];

    public function say(string $text): void
    {
        $this->said[] = $text;
    }

    public function store(string $name, ?string $value): void
    {
        $this->stored[$name] = $value;
    }

    public function value(string $name): ?string
    {
        return $this->stored[$name] ?? null;
    }

    public function fill(?string $template): string
    {
        $template = (string) $template;

        return (string) preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/u',
            fn (array $matches): string => (string) $this->value($matches[1]),
            $template,
        );
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return array_map(static fn (?string $value): string => (string) $value, $this->stored);
    }
}
