<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Calls in a person and falls silent for the rest of the conversation.
 *
 * Silent for the rest of it, not until the next node: the operator now owns the
 * thread, and a bot that woke up again on the visitor's next message would be
 * talking over them. That is why the node ends the branch instead of having an
 * exit.
 *
 * It is also the node the operator's notification hangs off, and this is the
 * only honest place for it. Ring the bell earlier — when the visitor writes,
 * when a scenario fails to match — and the desk fills with conversations the
 * bot was about to answer perfectly well; people learn to ignore the bell, and
 * the one conversation that genuinely needed them waits with the rest. Here,
 * every ring means the same thing: the bot has run out of answers and somebody
 * is waiting.
 */
final class HandoffStep extends StepType
{
    public static function key(): string
    {
        return 'handoff';
    }

    public function label(): string
    {
        return 'Hand to a person';
    }

    public function icon(): string
    {
        return 'heroicon-o-hand-raised';
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
        return StepResult::handoff()->traced(RunStep::HANDED_OFF);
    }
}
