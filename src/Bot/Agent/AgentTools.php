<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Agent;

use MB\FilamentChatbot\Ai\AiTool;
use MB\FilamentChatbot\Capabilities\CapabilityAction;

/**
 * What the model is allowed to ask for on this node, and nothing else.
 *
 * An allow-list rather than "every registered capability", and this is the
 * single most important sentence in the agent. Capabilities are how a host
 * teaches the bot to do things, and some of them cancel bookings, move money or
 * write to somebody's record. A model handed the whole registry would be one
 * confidently-worded customer message away from using any of them — and the
 * conversation that did it would look, in the transcript, like an ordinary
 * request politely granted.
 *
 * So the author of the scenario names the actions, per node, on the canvas. A
 * node that answers questions gets the knowledge search and nothing else; the
 * node that takes a tour request gets `create_client` and `create_order`. The
 * list is visible to whoever reviews the scenario, which is the property that
 * "the model decides what it needs" can never have.
 *
 * **Three tools are the package's own** and are switched on per node rather
 * than registered by the host, because all three are about the conversation
 * rather than about the application:
 *
 *  - `search_knowledge` — look something up in the agency's own material. The
 *    difference between an agent that answers from what the agency wrote and
 *    one that answers from whatever the model remembers about travel.
 *  - `remember` — write a value where the rest of the bot can read it. Without
 *    it the model's interview lives only in the transcript, and the manager who
 *    picks the thread up has to read it to find out the dates. The node offers
 *    it only once an author has named the slots it may write to, so a model
 *    remembering into nowhere is not a thing that can happen from the canvas.
 *  - `call_human` — say this needs a person. A model with no way to ask for
 *    help improvises instead, which is how a bot promises a refund.
 */
final class AgentTools
{
    public const SEARCH = 'search_knowledge';

    public const REMEMBER = 'remember';

    public const HUMAN = 'call_human';

    /**
     * @param  array<string, CapabilityAction>  $actions  keyed by the tool name each is offered under
     * @param  list<string>  $slots  the names `remember` may write to; empty allows any
     */
    public function __construct(
        private readonly array $actions = [],
        private readonly bool $canSearch = true,
        private readonly bool $canRemember = true,
        private readonly bool $canCallHuman = true,
        private readonly array $slots = [],
    ) {}

    /**
     * @param  list<CapabilityAction>  $actions
     * @param  list<string>  $slots
     */
    public static function of(array $actions, bool $canSearch, bool $canRemember, bool $canCallHuman, array $slots = []): self
    {
        $keyed = [];

        foreach ($actions as $action) {
            $keyed[AiTool::nameFor($action->key)] = $action;
        }

        return new self($keyed, $canSearch, $canRemember, $canCallHuman, $slots);
    }

    /**
     * The catalogue as the provider is told it.
     *
     * @return list<AiTool>
     */
    public function catalogue(): array
    {
        $tools = [];

        if ($this->canSearch) {
            $tools[] = new AiTool(
                self::SEARCH,
                __('chatbot::nodes.steps.ai_agent.tools.search'),
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => __('chatbot::nodes.steps.ai_agent.tools.search_query')],
                    ],
                    'required' => ['query'],
                ],
            );
        }

        if ($this->canRemember) {
            $field = ['type' => 'string', 'description' => __('chatbot::nodes.steps.ai_agent.tools.remember_field')];

            if ($this->slots !== []) {
                $field['enum'] = $this->slots;
            }

            $tools[] = new AiTool(
                self::REMEMBER,
                __('chatbot::nodes.steps.ai_agent.tools.remember'),
                [
                    'type' => 'object',
                    'properties' => [
                        'field' => $field,
                        'value' => ['type' => 'string', 'description' => __('chatbot::nodes.steps.ai_agent.tools.remember_value')],
                    ],
                    'required' => ['field', 'value'],
                ],
            );
        }

        if ($this->canCallHuman) {
            $tools[] = new AiTool(
                self::HUMAN,
                __('chatbot::nodes.steps.ai_agent.tools.human'),
                [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => __('chatbot::nodes.steps.ai_agent.tools.human_reason')],
                    ],
                    'required' => ['reason'],
                ],
            );
        }

        foreach ($this->actions as $action) {
            $tools[] = AiTool::fromCapability($action);
        }

        return $tools;
    }

    public function action(string $name): ?CapabilityAction
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * Whether this slot may be written.
     *
     * An empty list allows any name, which is the right default for a node
     * whose author wrote the instruction and knows what the scenario reads.
     * A named list is the stricter case: a `remember` the model invented —
     * `"notes"`, `"preferences"` — writes a variable nothing reads, and the
     * author sees a filled interview go nowhere.
     */
    public function mayRemember(string $field): bool
    {
        return $this->slots === [] || in_array($field, $this->slots, true);
    }

    /**
     * @return list<string>
     */
    public function slots(): array
    {
        return $this->slots;
    }

    public function searches(): bool
    {
        return $this->canSearch;
    }
}
