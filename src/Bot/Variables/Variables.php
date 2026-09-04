<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Variables;

use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Turns the `{{name}}` an author typed into the value a visitor reads.
 *
 * One class rather than a helper on each step, because every step that speaks
 * goes through it and the rules below are the difference between a message that
 * embarrasses the agency and one that does not. Written twice, the second copy
 * would be the one missing the recursion guard.
 */
final class Variables
{
    /**
     * The longest message this may produce.
     *
     * A template is a few hundred characters, but a variable is not: a source
     * may hand back a description field somebody pasted a document into, and
     * every channel rejects an oversized message with an error the visitor sees
     * as silence. Cutting here means the sentence arrives short instead of not
     * at all.
     */
    public const MAX_LENGTH = 4000;

    /**
     * Spaces inside the braces are allowed because authors type them. `{{ name }}`
     * left unmatched would be sent to the visitor as markup, which is exactly
     * the failure the empty-string rule below exists to prevent.
     */
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/u';

    public function __construct(private readonly ChatbotRegistry $registry) {}

    /**
     * Substitutes every placeholder in one pass.
     *
     * One pass is the whole point, and preg_replace_callback is used precisely
     * because it never re-reads what it has just written. A value that itself
     * contains `{{other}}` stays as those seven characters.
     *
     * That is not tidiness, it is the visitor's own text coming back: almost
     * every collected answer was typed by them. Expanding values would let
     * anyone answer "what is your name?" with `{{crm.card_number}}` and have the
     * bot read the next variable it is asked for out loud on the following turn.
     * The one place the visitor controls the input is the one place a template
     * engine must not be recursive.
     */
    public function fill(?string $template, Conversation $conversation, ?string $said = null): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $filled = preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $matches): string => $this->value($conversation, $matches[1], $said) ?? '',
            $template,
        );

        return $this->trimToLimit($filled ?? '');
    }

    /**
     * The value behind one name, or null when nothing claims it.
     *
     * Three lookups in a fixed order:
     *
     * 1. what the bot collected in this conversation;
     * 2. `source.field`, when the name carries a prefix;
     * 3. a bare field name, but only while exactly one source offers it.
     *
     * Collected answers win, and that order is the one that cannot surprise
     * anybody: the author who dropped a node named `name` and wrote `{{name}}`
     * on the line below is asking for the thing they just collected. Were a
     * source to win, installing a package that happens to publish a field of the
     * same name would silently change what published scenarios say — a greeting
     * addressed to the account holder instead of to the person typing.
     *
     * An ambiguous bare name resolves to nothing rather than to whichever source
     * was registered first. Registration order is an accident of composer's
     * dependency graph, and a sentence that changes meaning when an unrelated
     * package is installed is not something anybody would think to test.
     */
    public function value(Conversation $conversation, string $name, ?string $said = null): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $answers = $conversation->bot_answers;

        if (is_array($answers) && array_key_exists($name, $answers)) {
            return $this->stringify($answers[$name]);
        }

        if (str_contains($name, '.')) {
            [$sourceKey, $field] = explode('.', $name, 2);

            $source = $this->registry->variableSource($sourceKey);

            if ($source === null || ! array_key_exists($field, $source->fields())) {
                return null;
            }

            return $this->read($source, $conversation, $field, $said);
        }

        return $this->readUnprefixed($conversation, $name, $said);
    }

    /**
     * Every name that resolves right now, as name to label, for the editor's
     * picker.
     *
     * Source fields are listed under their prefix and only under it. Offering
     * the bare form as well would put a name in the picker that stops working
     * the day a second source publishes a field of the same name — and it would
     * stop working in scenarios nobody had touched.
     *
     * @return array<string, string>
     */
    public function known(Conversation $conversation): array
    {
        $known = [];

        $answers = $conversation->bot_answers;

        if (is_array($answers)) {
            foreach (array_keys($answers) as $name) {
                if (is_string($name) && $name !== '') {
                    $known[$name] = $name;
                }
            }
        }

        foreach ($this->registry->variableSources() as $source) {
            foreach ($source->fields() as $field => $label) {
                $known[$source->key().'.'.$field] = $source->label().' · '.$label;
            }
        }

        return $known;
    }

    /**
     * A bare name is only answered when the answer is not a guess.
     */
    private function readUnprefixed(Conversation $conversation, string $name, ?string $said): ?string
    {
        $found = null;

        foreach ($this->registry->variableSources() as $source) {
            if (! array_key_exists($name, $source->fields())) {
                continue;
            }

            if ($found !== null) {
                return null;
            }

            $found = $source;
        }

        return $found === null ? null : $this->read($found, $conversation, $name, $said);
    }

    /**
     * The built-in source is the only one that needs the current turn's text,
     * so it is the only one handed a copy of itself carrying it.
     */
    private function read(VariableSource $source, Conversation $conversation, string $field, ?string $said): ?string
    {
        if ($source instanceof TalkVariables) {
            $source = $source->withSaid($said);
        }

        return $source->value($conversation, $field);
    }

    /**
     * Anything a source or a collected answer may hold, as text.
     *
     * A list is joined rather than dropped: a multi-choice answer is the one
     * array shape that reaches a sentence, and an author who wrote
     * "you picked {{interests}}" would otherwise get a gap with nothing to
     * explain it. Anything else has no printable form and answers null.
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = array_filter($value, static fn (mixed $item): bool => is_scalar($item));

            return $parts === [] ? null : implode(', ', array_map(strval(...), $parts));
        }

        return null;
    }

    /**
     * Cuts at the last whitespace before the limit rather than mid-word.
     *
     * A message ending "your booking refere" reads as a bug in the bot; one
     * ending "your booking" reads as a message that ran long. The pattern only
     * matches when there is whitespace to cut at, so a single very long token —
     * a URL, an identifier — is left at its full width instead of being erased.
     */
    private function trimToLimit(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH);

        return rtrim(preg_replace('/\s+\S*$/u', '', $cut) ?? $cut);
    }
}
