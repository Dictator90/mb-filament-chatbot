<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps;

use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Everything a step gets when it runs.
 *
 * Passed as one object rather than five arguments so that later phases can add
 * to it — a data-resource reader, a capability gateway, an AI gateway — without
 * breaking the signature of every step a host has already written.
 */
final class StepContext
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Scenario $scenario,
        public readonly Step $step,
        public readonly Runtime $runtime,
        /**
         * The visitor's last message this turn, or null when the walk was not
         * started by something they said — a delay resuming, a scenario opening
         * on a greeting.
         *
         * Carried down the walk instead of being re-read at each step: it cannot
         * change within one turn, and re-reading would cost a query per step.
         */
        public readonly ?string $said = null,
    ) {}

    /**
     * A setting of this step, by the name its field descriptor declared.
     */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->step->setting($name, $default);
    }

    /**
     * A setting with {{name}} placeholders already substituted.
     */
    public function filled(string $name, string $default = ''): string
    {
        $value = $this->step->setting($name);

        return $this->runtime->fill(is_string($value) ? $value : $default);
    }
}
