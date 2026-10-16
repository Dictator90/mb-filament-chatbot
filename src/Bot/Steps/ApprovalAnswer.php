<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps;

/**
 * Reads a visitor's answer to "shall I go ahead?" as a yes, a no, or neither.
 *
 * Shared by the approval node and by the agent, which asks the same question
 * before an action that needs it. Two readings of "да" would drift apart on the
 * first edit to either, and the one that drifted towards "probably yes" would be
 * the one performing an irreversible thing for a person who was hesitating.
 */
final class ApprovalAnswer
{
    /**
     * True for a yes, false for a no, null when the answer says neither.
     *
     * Null is a third value rather than a default of false so that the caller
     * has to decide what to do with it out loud. Folded into false here, the
     * node would refuse on the first unclear word and never ask again, which is
     * the correct outcome reached rudely; folded into true it would be the
     * failure this whole class is written against.
     *
     * The labels are read before the built-in words, because an author who
     * relabelled the answers meant those labels to be what the visitor types.
     */
    public static function verdict(string $answer, string $yesLabel, string $noLabel): ?bool
    {
        $answer = self::normalise($answer);

        if ($answer === '') {
            return null;
        }

        $yes = self::normalise($yesLabel);
        $no = self::normalise($noLabel);

        if ($answer === $yes || $answer === '1') {
            return true;
        }

        if ($answer === $no || $answer === '2') {
            return false;
        }

        if (in_array($answer, self::words('yes'), true)) {
            return true;
        }

        if (in_array($answer, self::words('no'), true)) {
            return false;
        }

        if ($no !== '' && str_starts_with($no, $answer)) {
            return false;
        }

        if ($yes !== '' && str_starts_with($yes, $answer)) {
            return true;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function words(string $which): array
    {
        return array_values((array) __('chatbot::bot.approval.'.$which));
    }

    private static function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
