<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Sends one message and carries on.
 *
 * The node has no settings at all, and that is deliberate: its text is the
 * step's own body, the same column every speaking node uses. Given a field of
 * its own the text would be stored in two places for the same node — body for
 * the nodes the engine sends automatically, a setting for this one — and the
 * editor's preview, the version diff and the message the visitor receives would
 * each be free to read a different one.
 *
 * It does not send anything itself either. The engine sends the body of any
 * node whose speaks() is true before running it, so a say node that also said
 * its body would send it twice.
 */
final class SayStep extends StepType
{
    public static function key(): string
    {
        return 'say';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.say.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
    }

    public function isBasic(): bool
    {
        return true;
    }

    public function speaks(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'ask', 'delay', 'close'];
    }

    public function run(StepContext $context): StepResult
    {
        return StepResult::next()->traced(RunStep::SAID);
    }
}
