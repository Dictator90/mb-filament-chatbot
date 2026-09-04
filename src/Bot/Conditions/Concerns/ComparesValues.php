<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Concerns;

/**
 * Every operator a condition can be asked, answered once for all of them.
 *
 * The set is shared on purpose. "Equals" has to mean the same thing on a
 * channel, on a collected answer and on a host's own field, and the moment a
 * second field writes its own comparison the two drift: one trims, the other
 * does not, and an author who moved a branch from one field to another finds it
 * stops matching for no visible reason.
 *
 * A trait rather than a method on the abstract field, so that the evaluator and
 * host-written fields can share the same implementation without the package
 * dictating what they extend. Protected rather than private because the point
 * is that subclasses call it.
 */
trait ComparesValues
{
    /**
     * `$actual` is null when the value was never established at all, and that is
     * not the same as an empty answer. Every comparison that names a value
     * answers false for null — a branch drawn as "is not Moscow" was drawn about
     * an answer that exists, and firing it for a question nobody asked sends the
     * conversation down a path built on a fact the bot does not have.
     *
     * `filled` and `blank` are the two that read the difference. `filled` wants
     * something with content in it; `blank` wants a value that is present and
     * empty — somebody answered and answered with nothing. Never asked is
     * neither, and falls through to "otherwise", which is where a scenario asks
     * the question for the first time. Let `blank` swallow null as well and a
     * branch meant to say "that answer was empty, try again" would say it to a
     * visitor who was never asked.
     */
    protected function compare(string $operator, ?string $actual, mixed $expected): bool
    {
        return match ($operator) {
            'yes' => $this->isTruthy($actual),
            'no' => ! $this->isTruthy($actual),
            'filled' => $actual !== null && $this->normalize($actual) !== '',
            'blank' => $actual !== null && $this->normalize($actual) === '',
            'equals' => $actual !== null && $this->matches($actual, $expected),
            'not_equals' => $actual !== null && ! $this->matches($actual, $expected),
            'contains' => $actual !== null && $this->holds($actual, $expected, false),
            'starts_with' => $actual !== null && $this->holds($actual, $expected, true),
            'one_of' => $actual !== null && $this->isOneOf($actual, $expected),
            'at_least' => $actual !== null && $this->ordered($actual, $expected, true),
            'at_most' => $actual !== null && $this->ordered($actual, $expected, false),
            default => false,
        };
    }

    /**
     * Numbers are compared as numbers and everything else case-insensitively
     * without its outer spaces.
     *
     * The numeric branch is what makes a threshold typed as "5" match a value
     * stored as "5.0" — the same amount written by two different channels, which
     * as strings are not equal and would take an author several confused
     * evenings to explain.
     */
    private function matches(string $actual, mixed $expected): bool
    {
        $wanted = $this->text($expected);

        if ($wanted === null) {
            return false;
        }

        if (is_numeric($actual) && is_numeric($wanted)) {
            return (float) $actual === (float) $wanted;
        }

        return $this->normalize($actual) === $this->normalize($wanted);
    }

    /**
     * An empty needle answers false rather than true.
     *
     * `str_contains` says every string contains "", so an author who chose
     * "contains" and then cleared the box would have drawn a branch that always
     * fires — and it fires before the branches below it, so the rest of the node
     * becomes unreachable with nothing on the canvas to show it.
     */
    private function holds(string $actual, mixed $expected, bool $fromStart): bool
    {
        $needle = $this->normalize($this->text($expected) ?? '');

        if ($needle === '') {
            return false;
        }

        $haystack = $this->normalize($actual);

        return $fromStart
            ? str_starts_with($haystack, $needle)
            : str_contains($haystack, $needle);
    }

    /**
     * Accepts both a real list and one line of comma-separated values, because
     * the editor stores whichever the field's widget produced and a published
     * scenario may hold either shape from before the widget changed.
     */
    private function isOneOf(string $actual, mixed $expected): bool
    {
        $candidates = is_array($expected)
            ? $expected
            : explode(',', $this->text($expected) ?? '');

        foreach ($candidates as $candidate) {
            if ($this->text($candidate) !== null && $this->matches($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$actual` sits on the wanted side of `$expected`.
     *
     * An expectation with nothing in it fails both directions rather than
     * defaulting to one of them. Written as an ordering that returns a number,
     * "no expectation" would have to be some number, and whichever it was would
     * satisfy either `at_least` or `at_most` — an author who left the box empty
     * would have drawn a branch that always fires and hides every branch under
     * it.
     *
     * Times and dates are compared as strings on purpose: the fields hand them
     * over zero-padded, and 'Y-m-d' and 'H:i' both sort correctly that way. What
     * they must not do is fall into the numeric branch, which is why the fields
     * normalise before they call this.
     */
    private function ordered(string $actual, mixed $expected, bool $atLeast): bool
    {
        $wanted = $this->text($expected);

        if ($wanted === null || trim($wanted) === '') {
            return false;
        }

        $rank = is_numeric($actual) && is_numeric($wanted)
            ? (float) $actual <=> (float) $wanted
            : strcmp($this->normalize($actual), $this->normalize($wanted)) <=> 0;

        return $atLeast ? $rank >= 0 : $rank <= 0;
    }

    private function isTruthy(?string $value): bool
    {
        return $value !== null
            && in_array($this->normalize($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function text(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
