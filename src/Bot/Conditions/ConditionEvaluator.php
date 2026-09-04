<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions;

use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Answers one branch's condition.
 *
 * Everything unrecognised answers false rather than throwing, and that is the
 * single decision this class is about. A condition naming a field whose package
 * has been removed, or an operator the editor no longer offers, is a fault in
 * the scenario — but it turns up mid-conversation, with a customer waiting. A
 * false sends them down the "otherwise" branch and the conversation continues;
 * an exception ends it with a stack trace and nothing said.
 *
 * The scenario is still visibly wrong: the branch never fires, the run journal
 * records which condition was unrecognised, and the editor marks the node. It
 * fails loudly to the person who can fix it and quietly to the person who
 * cannot.
 */
class ConditionEvaluator
{
    public function __construct(private readonly ChatbotRegistry $registry) {}

    /**
     * @param  array{field?: string, name?: string, operator?: string, value?: mixed}  $when
     */
    public function evaluate(array $when, Conversation $conversation, Runtime $runtime): bool
    {
        $field = $this->registry->condition(trim((string) ($when['field'] ?? '')));

        if ($field === null) {
            return false;
        }

        $operator = trim((string) ($when['operator'] ?? ''));

        /*
         * An operator outside the field's own kind is checked here rather than
         * inside each field. A field asked "is 'contains' true of a yes/no
         * question" has no sensible answer, and leaving every field to notice
         * that for itself means every host-written field has to remember to.
         */
        if (! in_array($operator, $field->kind()->operators(), true)) {
            return false;
        }

        $name = trim((string) ($when['name'] ?? ''));

        if ($field instanceof ReceivesName) {
            if ($name === '') {
                return false;
            }

            $field = $field->withName($name);
        }

        return $field->evaluate($conversation, $operator, $when['value'] ?? null, $runtime);
    }

    /**
     * Whether a branch is described well enough to ever fire.
     *
     * Used by the editor, not by the walk: a branch that can never be true looks
     * exactly like one that simply has not matched yet, and the difference is
     * only visible while somebody is still editing.
     *
     * @param  array{field?: string, name?: string, operator?: string, value?: mixed}  $when
     */
    public function isUsable(array $when): bool
    {
        $field = $this->registry->condition(trim((string) ($when['field'] ?? '')));

        if ($field === null) {
            return false;
        }

        $operator = trim((string) ($when['operator'] ?? ''));

        if (! in_array($operator, $field->kind()->operators(), true)) {
            return false;
        }

        if ($field instanceof ReceivesName && trim((string) ($when['name'] ?? '')) === '') {
            return false;
        }

        return ! ConditionKind::operatorNeedsValue($operator)
            || ($when['value'] ?? null) !== null;
    }
}
