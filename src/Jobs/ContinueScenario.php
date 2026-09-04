<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Picks a scenario back up after a pause.
 *
 * A queued job rather than a sleeping request, because the pause exists to make
 * the bot's messages land at a human pace and a request held open for that would
 * hold a worker open too. Three seconds of politeness per conversation is a
 * whole server at any volume.
 *
 * The step key travels with the job and is checked on arrival. A visitor who
 * wrote again during the pause has already moved the conversation on, and
 * resuming an abandoned pause would drop them back into a branch they left.
 */
class ContinueScenario implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $stepKey,
    ) {}

    public function handle(BotEngine $engine): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        $engine->resumeAfterDelay($conversation, $this->stepKey);
    }
}
