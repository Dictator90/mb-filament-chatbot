<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Tests\Support;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Contracts\HasSideEffect;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Models\Step;

/**
 * A host node that changes data, used to test the release check.
 *
 * Written here rather than borrowing a built-in kind because the check under
 * test is the general one: any node a host declares as writing must be refused
 * a release while a conversation can reach it without being asked first. A test
 * that leaned on the package's own action node would only prove the check
 * recognises that one node.
 */
final class RecordingWriteStep extends StepType implements HasSideEffect
{
    public static function key(): string
    {
        return 'tests.write';
    }

    public function label(): string
    {
        return 'Change something';
    }

    public function sideEffect(Step $step): SideEffect
    {
        return SideEffect::Write;
    }

    public function run(StepContext $context): StepResult
    {
        return StepResult::next();
    }
}
