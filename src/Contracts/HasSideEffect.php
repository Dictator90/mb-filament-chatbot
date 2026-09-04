<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Contracts;

use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Models\Step;

/**
 * A step kind that may change the host's data rather than only read it.
 *
 * Declared by the kind instead of guessed from its key, because the graph
 * checker has to refuse to publish a scenario that writes without asking
 * first, and guessing gets that wrong in the direction that costs money: a
 * host node called "sync_order" reads, a node called "confirm" charges a card,
 * and no naming rule tells them apart. A kind that says nothing is treated as
 * a read, which is safe — the worst outcome is a warning that never appears
 * for a node whose author never claimed it writes.
 *
 * The answer takes the step and not just the kind, because one kind covers
 * many nodes: the node that runs a host capability writes or reads depending
 * on which capability was chosen in its settings, and a per-kind answer would
 * mark every one of them as dangerous — a warning on every node is a warning
 * nobody reads.
 */
interface HasSideEffect
{
    public function sideEffect(Step $step): SideEffect;
}
