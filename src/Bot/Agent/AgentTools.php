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
     * The tool with which the model says its part is finished.
     *
     * The alternative was a condition on the canvas — "once these values are
     * filled, carry on" — and it was tried on a live conversation. It fails in
     * a way nothing else could have shown: the model asks "куда бы Вы хотели
     * поехать?", the visitor's previous message happened to complete the list,
     * and the scenario speaks over the question it had just asked. The customer
     * reads a question and then a summary answering a different one.
     *
     * Only the model knows whether it has just asked something. So the hand-off
     * is its own decision, and the node's first exit is taken on this tool
     * rather than after every answer.
     */
    public const PASS = 'pass_on';

    /**
     * The prefix a declared data resource is offered to the model under.
     *
     * Prefixed rather than named after the resource alone, so that a resource
     * and a capability can share a name without one shadowing the other — and
     * so the model reads the catalogue as "reading" against "doing", which is
     * the distinction it should be most careful about.
     */
    public const READ = 'read_';

    /**
     * @param  array<string, CapabilityAction>  $actions  keyed by the tool name each is offered under
     * @param  array<string, array{key: string, label: string, description: string}>  $resources  keyed the same way
     * @param  list<string>  $slots  the names `remember` may write to; empty allows any
     */
    public function __construct(
        private readonly array $actions = [],
        private readonly bool $canSearch = true,
        private readonly bool $canRemember = true,
        private readonly bool $canCallHuman = true,
        private readonly array $slots = [],
        private readonly array $resources = [],
        private readonly bool $canPassOn = false,
    ) {}

    /**
     * @param  list<CapabilityAction>  $actions
     * @param  list<string>  $slots
     * @param  list<array{key: string, label: string, description: string}>  $resources
     */
    public static function of(
        array $actions,
        bool $canSearch,
        bool $canRemember,
        bool $canCallHuman,
        array $slots = [],
        array $resources = [],
        bool $canPassOn = false,
    ): self {
        $keyed = [];

        foreach ($actions as $action) {
            $keyed[AiTool::nameFor($action->key)] = $action;
        }

        $reads = [];

        foreach ($resources as $resource) {
            $reads[self::READ.AiTool::nameFor((string) $resource['key'])] = $resource;
        }

        return new self($keyed, $canSearch, $canRemember, $canCallHuman, $slots, $reads, $canPassOn);
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

        if ($this->canPassOn) {
            $tools[] = new AiTool(
                self::PASS,
                __('chatbot::nodes.steps.ai_agent.tools.pass'),
                [
                    'type' => 'object',
                    'properties' => [
                        'note' => ['type' => 'string', 'description' => __('chatbot::nodes.steps.ai_agent.tools.pass_note')],
                    ],
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

        foreach ($this->resources as $name => $resource) {
            $tools[] = new AiTool(
                $name,
                trim($resource['label'].'. '.$resource['description']),
                [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'number', 'description' => __('chatbot::nodes.steps.ai_agent.tools.read_limit')],
                    ],
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
     * The declared resource behind a `read_…` tool, or null.
     *
     * **No filters are offered with it, and that is a decision rather than a
     * gap.** A resource narrows itself to this conversation's own person
     * through scopes the host declared, resolved from server state — a model is
     * never shown and never asked for the value that does the narrowing. What a
     * filter would add is the model naming fields and operators, which it does
     * by guessing: every guess outside the declaration is a refusal, a round
     * spent, and a sentence to the visitor about a field they never mentioned.
     * The resource's own default sort and limit answer the question that is
     * actually asked — "what are my trips" — without any of that.
     */
    public function resource(string $name): ?string
    {
        return isset($this->resources[$name]) ? (string) $this->resources[$name]['key'] : null;
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
