<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

/**
 * The few things a step is allowed to do besides deciding where to go next.
 *
 * An interface rather than the engine class so that a step depends on what it
 * needs and not on the walker. It also keeps steps testable in isolation: a
 * step's own test drives it with a recording runtime and asserts what it tried
 * to say, without a conversation, a scenario or a database behind it.
 *
 * The surface grows one method at a time, and deliberately slowly. Everything
 * added here becomes something a host-written step may call, and therefore
 * something the package can no longer change freely.
 */
interface Runtime
{
    /**
     * Sends a message to the visitor, through whichever channel this
     * conversation arrived on.
     */
    public function say(string $text): void;

    /**
     * Remembers a value under a name, for later steps and for message templates.
     *
     * An empty value is still written rather than skipped: a step may run a
     * second time against a different record, and a value left over from the
     * first run is a sentence about somebody else's data.
     */
    public function store(string $name, ?string $value): void;

    /**
     * Reads a value the conversation already knows — collected, set, or built in.
     */
    public function value(string $name): ?string;

    /**
     * Substitutes {{name}} placeholders in a template.
     *
     * Unknown names resolve to an empty string rather than being left as-is.
     * Sending a visitor a literal "{{ first_name }}" is worse than sending a
     * sentence with a gap in it, and an author notices the gap immediately.
     */
    public function fill(?string $template): string;

    /**
     * Every name that has a value right now, for the editor's variable picker.
     *
     * @return array<string, string>
     */
    public function variables(): array;
}
