<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

/**
 * What reading one answer produced.
 *
 * A read either succeeded or did not, and the difference has to survive the
 * trip back to the engine: an unreadable answer is asked about once more, a
 * readable one is stored and the conversation moves on. Returning a bare string
 * would collapse the two — an empty string is both "nothing was understood" and
 * "the person answered with nothing", and the bot would ask forever or store a
 * blank as if it were a phone number.
 *
 * The raw answer is kept next to the parsed one because the re-ask has to quote
 * what the person actually wrote. "I could not read '9 evenings'" is a question
 * someone can answer; "I could not read that" is not.
 */
final class SlotValue
{
    /**
     * @param  array<string, string>  $extra
     */
    private function __construct(
        private readonly bool $ok,
        private readonly ?string $value,
        private readonly string $raw,
        private readonly array $extra,
    ) {}

    /**
     * A read that worked.
     *
     * `extra` carries the facts that do not fit in a single line of text, and it
     * exists because `value` has two jobs at once: it is stored under the slot
     * key and it is pasted into the bot's next reply. A range read as
     * "2026-05-10..2026-05-17" would be correct in the store and unreadable in
     * the reply — a person seeing that in a confirmation assumes the bot broke.
     * So `value` stays the sentence a human would write ("10.05.2026 –
     * 17.05.2026") and `extra` holds the machine halves the host actually
     * queries on: `from` and `to` for a range, `digits` for a phone.
     *
     * @param  array<string, string>  $extra
     */
    public static function parsed(string $value, array $extra = []): self
    {
        return new self(true, $value, $value, $extra);
    }

    /**
     * A read that did not work, with the answer preserved exactly as typed.
     *
     * Not trimmed and not shortened: the answer is what the re-ask quotes and
     * what an operator reads when they take the conversation over, and a bot
     * that quietly reshapes what someone wrote makes that handover misleading.
     */
    public static function missed(string $raw): self
    {
        return new self(false, null, $raw, []);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * @return array<string, string>
     */
    public function extra(): array
    {
        return $this->extra;
    }
}
