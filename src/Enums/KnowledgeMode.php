<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What the bot is allowed to do with a matched knowledge topic.
 *
 * The mode is a property of the topic, not of the answer text, because the same
 * question asked twice deserves the same treatment: a topic that must never be
 * answered from a model has to stay unanswerable even after somebody fills the
 * answer field in.
 */
enum KnowledgeMode: string implements HasLabel
{
    /**
     * Reply with the recorded text as written.
     */
    case Answer = 'answer';

    /**
     * Reply, but mark the reply as worth a second look.
     *
     * For topics whose answer is right most of the time and expensive when it is
     * not — anything seasonal, anything the operator changes without touching the
     * knowledge base.
     */
    case Verify = 'verify';

    /**
     * Never reply. Hand the conversation to a person.
     *
     * There are topics where an invented answer is worse than saying nothing:
     * price, refund windows, house rules. A wrong number in any of them is quoted
     * back at the company as a promise, and the customer is right to quote it.
     * These entries exist to stop an answer, so they stay useful even empty.
     */
    case Handoff = 'handoff';

    /**
     * Do not answer — start the named scenario instead.
     *
     * "Where is my order" is a lookup wearing a question's clothes; the useful
     * reply is a form, not a paragraph.
     */
    case Scenario = 'scenario';

    /**
     * Interrupt whatever is happening and reach a person now.
     *
     * Separate from Handoff because the trigger is different: Handoff is a topic
     * the bot may not speak about, Urgent is a topic that may not wait in a queue.
     */
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return __('chatbot::panel.enums.knowledge_mode.'.$this->value);
    }
}
