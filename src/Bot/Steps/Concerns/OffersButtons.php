<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Concerns;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Remembers the buttons a node has just drawn, so that the answer can be read
 * as the label the person actually saw.
 *
 * **A pressed button does not arrive as its text.** Telegram caps callback data
 * at 64 bytes and a label written by a scenario author is routinely longer, so
 * the driver sends the button's position instead — the engine receives "1". A
 * choice slot copes, because its parser accepts a position; everything else
 * does not, and the two failures are invisible:
 *
 * - the "Skip" button on an optional question arrived as "1", which is not a
 *   skip word, so the question was not skipped — "1" was stored as the answer,
 *   and a manager later read "1" as the visitor's wish;
 * - `<slot>_said`, which exists so a summary can quote the visitor, held "1"
 *   as well. The confirmation the visitor is asked to approve then read
 *   "Board basis: 2" — a bot that looks like it is reading from a broken form.
 *
 * The node drew the buttons, so the node is what can translate them back.
 * Positions are only translated while the buttons are still on screen: the map
 * is cleared as soon as the answer has been read, so a bare "1" typed later in
 * the conversation stays the number one.
 */
trait OffersButtons
{
    /**
     * @param  list<string>  $labels
     */
    protected function rememberButtons(StepContext $context, array $labels): void
    {
        $meta = (array) $context->conversation->meta;
        $key = $this->buttonsKey($context);

        if ($labels === []) {
            unset($meta[$key]);
        } else {
            $meta[$key] = array_values($labels);
        }

        $this->saveButtons($context->conversation, $meta);
    }

    /**
     * The label of the button this answer pressed, or the answer as typed.
     *
     * A number outside the list is left alone: on a question offering three
     * buttons, "7" is somebody answering with a number, not pressing a fourth
     * button that does not exist.
     */
    protected function pressedLabel(StepContext $context, string $answer): string
    {
        $labels = (array) (((array) $context->conversation->meta)[$this->buttonsKey($context)] ?? []);
        $position = trim($answer);

        if ($labels === [] || preg_match('/^\d+$/', $position) !== 1) {
            return $answer;
        }

        $index = (int) $position - 1;

        return isset($labels[$index]) ? (string) $labels[$index] : $answer;
    }

    protected function forgetButtons(StepContext $context): void
    {
        $meta = (array) $context->conversation->meta;
        unset($meta[$this->buttonsKey($context)]);

        $this->saveButtons($context->conversation, $meta);
    }

    /**
     * Per node, not per conversation: a scenario may hold two forms, and the
     * conversation can be inside the second while the first is still waiting
     * in a sub-scenario that has not returned yet.
     */
    private function buttonsKey(StepContext $context): string
    {
        return 'buttons:'.$context->step->key;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function saveButtons(Conversation $conversation, array $meta): void
    {
        $conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }
}
