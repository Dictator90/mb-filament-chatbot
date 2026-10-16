<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * What is struck out of a visitor's own words before they leave the machine.
 *
 * The package is careful about what it *assembles* for a model — a prompt is a
 * question and the knowledge fragments it may answer from, and nothing else
 * ({@see AiPrompt}). None of that care touches the one part nobody composes:
 * what the person typed. "Call me on 8 949 415-74-04", "my booking is
 * O-260828-V6I9", "passport 4510 123456" — that is personal data, it arrives
 * on its own, and it goes straight to a provider unless something removes it.
 *
 * **Replaced, not deleted.** An empty gap where a phone number was turns "call
 * me on 8 949…" into a broken sentence, and the model starts answering the
 * break instead of the question. A placeholder keeps the meaning of what was
 * said without keeping the data.
 *
 * **The order of the rules is load-bearing** and was settled against real
 * messages, not invented. A booking reference contains six digits in a row and
 * a phone number contains eleven; the general "long number" rule applied first
 * would swallow both, and neither the bot nor the person reading the transcript
 * afterwards could tell what had been said.
 *
 * The patterns are configuration, because what counts as a reference number is
 * the host's own shape — an order number, a policy number, a ticket. Anything
 * listed in `chatbot.ai.redact.patterns` runs before the built-in rules, in the
 * order given, which is where a host puts a shape that would otherwise be eaten
 * by the phone rule.
 */
final class Redactor
{
    private const EMAIL = '/[\w.+-]+@[\w-]+\.[\w.-]+/u';

    /*
     * A phone number is ten digits or more with spaces, brackets and dashes
     * allowed between them. Ten rather than eleven: a landline, and a number
     * written without its country code, are both phone numbers.
     *
     * The separators sit *between* digits and never after the last one.
     * Written the obvious way — a digit followed by optional separators,
     * repeated — the final repetition swallows the space that ends the number,
     * and "call me on 8 949 415-74-04 after six" comes back as "[phone]after
     * six": one word the model then has to make sense of, in every message
     * that mentions a number mid-sentence.
     */
    private const PHONE = '/(?<!\d)\+?\d(?:[\s\-()]*\d){9,14}(?!\d)/u';

    /**
     * The contacts a message carries, before they are struck out of it.
     *
     * Seen live: a customer typed their phone number twice, the model saw
     * "[phone]" both times and asked a third time — it can only write down
     * what it is shown. The number still must not leave the machine, so it is
     * taken here, on the server, and the model is told only that it was.
     *
     * @return array{phone: list<string>, email: list<string>}
     */
    public function contacts(?string $said): array
    {
        $text = trim((string) $said);

        preg_match_all(self::EMAIL, $text, $emails);
        preg_match_all(self::PHONE, (string) preg_replace(self::EMAIL, ' ', $text), $phones);

        return [
            'phone' => array_values(array_map('trim', $phones[0] ?? [])),
            'email' => array_values($emails[0] ?? []),
        ];
    }

    /**
     * A message fit to leave the machine.
     */
    public function clean(?string $said): string
    {
        $text = trim((string) $said);

        if ($text === '' || ! (bool) config('chatbot.ai.redact.enabled', true)) {
            return $text;
        }

        /*
         * Shapes the host says are safe to send — its own order and booking
         * references — are set aside before any rule runs and put back after.
         *
         * Seen live: a customer asked about «O-261007-ZHAE», the six digits in
         * the middle became "[number]", and the model, unable to find
         * "O-[number]-ZHAE", told the customer it could not see such a booking.
         * A reference number is not personal data; it is the one thing the
         * customer needs the bot to read.
         */
        $kept = [];

        foreach ($this->keptPatterns() as $pattern) {
            $text = (string) preg_replace_callback($pattern, static function (array $match) use (&$kept): string {
                $kept[] = $match[0];

                return "\u{E000}".(count($kept) - 1)."\u{E001}";
            }, $text);
        }

        $text = $this->redactAll($text);

        return (string) preg_replace_callback(
            "/\u{E000}(\\d+)\u{E001}/u",
            static fn (array $match): string => $kept[(int) $match[1]] ?? '',
            $text,
        );
    }

    /**
     * Patterns whose matches are never redacted, from
     * `chatbot.ai.redact.keep`. Invalid ones are skipped, as host patterns are.
     *
     * @return list<string>
     */
    private function keptPatterns(): array
    {
        return array_values(array_filter(
            (array) config('chatbot.ai.redact.keep', []),
            static fn (mixed $pattern): bool => is_string($pattern) && @preg_match($pattern, '') !== false,
        ));
    }

    private function redactAll(string $text): string
    {
        foreach ($this->hostPatterns() as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        $text = (string) preg_replace(self::EMAIL, $this->label('email'), $text);
        $text = (string) preg_replace(self::PHONE, $this->label('phone'), $text);

        /*
         * Everything else long enough to be an identifier — a passport, a card,
         * an account. Six digits rather than five or eight so that the year of
         * a trip survives: a budget written as "200000" is lost with it, which
         * is a real cost and a smaller one than a card number leaving.
         */
        $text = (string) preg_replace(
            '/(?<!\d)\d{6,}(?!\d)/u',
            $this->label('number'),
            $text,
        );

        return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    /**
     * Host shapes, checked before they can be mistaken for something else.
     *
     * Invalid patterns are skipped rather than thrown, and that is the safe
     * side here: this runs on the path that answers a visitor, and a typo in a
     * config file must not turn every question into an error. The rules below
     * still apply, so a broken reference pattern degrades into "[number]" —
     * unhelpful, never leaky.
     *
     * @return array<string, string>
     */
    private function hostPatterns(): array
    {
        $patterns = [];

        foreach ((array) config('chatbot.ai.redact.patterns', []) as $pattern => $replacement) {
            if (! is_string($pattern) || @preg_match($pattern, '') === false) {
                continue;
            }

            $patterns[$pattern] = is_string($replacement) ? $replacement : $this->label('number');
        }

        return $patterns;
    }

    private function label(string $kind): string
    {
        return (string) __("chatbot::bot.redacted.{$kind}");
    }
}
