<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Says goodbye and closes the conversation.
 *
 * Closing is a state, not a deletion and not a door being locked. The thread,
 * its messages and its trace stay exactly where they were, the visitor is free
 * to write again, and their next message reopens the conversation and starts a
 * new walk. Read as "the visitor may no longer write", this node would be the
 * cruellest thing in the package — somebody with one more question would find
 * the chat refusing them — so it is worth being explicit that it does nothing
 * of the sort.
 *
 * What it does buy is a desk that means something. Without it every finished
 * conversation stays open forever, the operator's list grows without bound, and
 * the ones that genuinely need an answer are lost among conversations that
 * ended politely three weeks ago.
 */
final class CloseStep extends StepType
{
    public static function key(): string
    {
        return 'close';
    }

    public function label(): string
    {
        return 'Close';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::None;
    }

    public function isBasic(): bool
    {
        return true;
    }

    public function speaks(): bool
    {
        return true;
    }

    public function ends(): bool
    {
        return true;
    }

    public function run(StepContext $context): StepResult
    {
        return StepResult::close()->traced(RunStep::CLOSED);
    }
}
