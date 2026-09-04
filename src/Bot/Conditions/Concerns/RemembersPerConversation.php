<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Concerns;

use Closure;
use MB\FilamentChatbot\Models\Conversation;
use WeakMap;

/**
 * Answers a condition's database question once per conversation instead of once
 * per branch.
 *
 * Condition fields are resolved from the registry and reused: one instance of
 * "has this person written before" answers for every conversation in the
 * process. A scenario routinely puts several branches on one node — returning
 * visitor, returning visitor out of hours, returning visitor with three turns
 * behind them — and each is a separate call. Without a memo the same COUNT runs
 * three times for one arrow drawn on a canvas, and it scales with how carefully
 * the author drew the node rather than with anything the visitor did.
 *
 * Keyed by the conversation object rather than by its id, and that is the part
 * worth keeping. An id survives the turn: a worker that answered a conversation
 * an hour ago would hand out the old count for the same id now, and the bot
 * would branch on a number of turns that stopped being true. A fresh turn loads
 * a fresh model, so the object is new and so is the answer. A WeakMap also lets
 * the entry go when the conversation does, which an id-keyed array cannot — in a
 * queue worker that array is a leak that grows for the life of the process.
 */
trait RemembersPerConversation
{
    /** @var WeakMap<Conversation, mixed>|null */
    private ?WeakMap $memo = null;

    /**
     * @param  Closure(): mixed  $compute
     */
    protected function remember(Conversation $conversation, Closure $compute): mixed
    {
        $this->memo ??= new WeakMap;

        if (! isset($this->memo[$conversation])) {
            $this->memo[$conversation] = $compute();
        }

        return $this->memo[$conversation];
    }
}
