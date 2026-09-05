<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Routing;

use Illuminate\Support\Collection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;

/**
 * A second opinion on which scenario should answer, asked only when the
 * deterministic rules produced none.
 *
 * The interface exists so that the direction of the dependency is the safe one:
 * the picker asks a router, and knows nothing about models, providers or
 * prompts. Written the other way round — a model layer that reaches into the
 * picker and overrides it — every AI failure would have to be repaired by
 * re-running the picker's own rules from inside the AI code, and the two copies
 * of "what happens when nothing matches" would drift apart on the first change.
 *
 * With no router bound the picker behaves exactly as it did before any of this
 * existed, which is the property the whole AI layer is arranged to preserve.
 */
interface ScenarioRouter
{
    /**
     * The scenario that should answer, or null to leave the decision alone.
     *
     * Null is the answer for everything that goes wrong as well as for "none of
     * these fit": no model, a refusal, an unreadable reply, a name that was not
     * on the list. A router that cannot decide must never guess, because the
     * caller's fallback is a scenario somebody drew on purpose.
     *
     * @param  Collection<int, Scenario>  $scenarios  the only scenarios that may be chosen from
     */
    public function route(Conversation $conversation, ?string $said, Collection $scenarios): ?Scenario;
}
