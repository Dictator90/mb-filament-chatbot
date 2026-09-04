<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions;

/**
 * A condition that is really a family, and needs to be told which member.
 *
 * "A collected variable" is not one question but as many as the bot has ever
 * collected, and `needsName()` already tells the editor to ask which. The name
 * then has to reach the field, and `evaluate()` has no parameter for it.
 *
 * Adding one would have been the obvious move and is the wrong one: every
 * condition a host has written implements that signature, so a fifth parameter
 * breaks all of them — to carry something the overwhelming majority never look
 * at. An interface asks only the two or three fields that need a name to say so.
 *
 * `withName()` returns a copy for the same reason `TalkVariables::withSaid()`
 * does: the registry hands one instance to every conversation, and a setter
 * would leave one node's variable name on the shared object for the next node,
 * the next conversation and the next visitor to read.
 */
interface ReceivesName
{
    public function withName(?string $name): static;
}
