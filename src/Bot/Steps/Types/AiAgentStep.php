<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Ai\AiConversationGateway;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiMessage;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\Redactor;
use MB\FilamentChatbot\Bot\Agent\AgentLoop;
use MB\FilamentChatbot\Bot\Agent\AgentTools;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\ApprovalAnswer;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Capabilities\CapabilityGateway;
use MB\FilamentChatbot\Chat\ConversationMemory;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Hands one turn to the model: it reads the thread, may use the tools this node
 * offers, and answers in its own words.
 *
 * **The difference from the answer node is who decides what happens.** `ai`
 * answers one question from material the scenario found for it, and the
 * scenario decides everything else: what to ask next, when to file the request,
 * when to fetch a person. Here those decisions are the model's, inside the
 * bounds this node draws — which tools exist, which values may be written, how
 * many rounds it gets, how much of the thread it sees. That is what makes a
 * conversation feel answered rather than processed, and it is also why the
 * bounds are on the canvas and not in the instruction: a sentence in a prompt
 * is a request, a missing tool is a fact.
 *
 * **A scripted form is still the better place for a required interview.** The
 * twelve questions an agency needs are a list somebody is accountable for; a
 * model asked to collect them will get eleven and improvise the twelfth. The
 * division that works is this node for the conversation — greeting, questions
 * about anything, reading a booking, deciding what the person actually wants —
 * with `remember` writing what it learns into the same slots a form would have
 * filled, so whatever the model did get is there for the manager and for the
 * nodes downstream.
 *
 * **What this node cannot promise, and `ai` can.** That one refuses to speak an
 * answer whose claimed sources were not among the fragments it handed over —
 * the check that makes an invented refund policy harmless. Here the answer is
 * composed after several tool results, the model was never asked to name a
 * source for it, and there is nothing to check a claim against. What remains is
 * narrower and real: the knowledge search goes through `KnowledgeSource`, which
 * never offers a topic whose mode says "fetch a person" or "run a scenario", so
 * the material the model can answer from is material somebody approved for
 * answering. Everything past that is the instruction's job, which is why the
 * instruction is required and why its wording lists the forbidden subjects by
 * name rather than asking for care in general.
 *
 * Two exits. The second covers everything that is not an answer to speak: no
 * conversation-capable driver, a provider refusing, the budget spent, the model
 * running out of rounds — and the one that is not a failure at all, the model
 * deciding this needs a person. A scenario that leaves it unconnected goes
 * silent on the first bad afternoon at the provider.
 */
final class AiAgentStep extends StepType
{
    public function __construct(
        private readonly AiGatewayFactory $gateways,
        private readonly AgentLoop $loop,
        private readonly ConversationMemory $memory,
        private readonly ChatbotRegistry $registry,
        private readonly Redactor $redactor,
        private readonly CapabilityGateway $capabilities,
    ) {}

    /**
     * Puts the model's question to the visitor with yes/no buttons, and keeps
     * the action until the answer comes back.
     *
     * The note under the question is the author's and is added by code, not by
     * the model: it is where an agency puts "pressing «Да» you agree to the
     * processing of personal data", and a sentence that must be shown every
     * time cannot depend on a model remembering to write it.
     *
     * @param  array{action: string, input: array<string, mixed>}  $pending
     */
    private function askApproval(StepContext $context, array $pending, string $text): StepResult
    {
        $action = $this->registry->capability($pending['action']);

        $question = trim($text) !== ''
            ? trim($text)
            : __('chatbot::nodes.steps.ai_agent.approval_question', ['action' => $action?->label ?? $pending['action']]);

        $note = trim($context->filled('approval_note'));

        $context->runtime->say(
            $note === '' ? $question : $question."\n\n".$note,
            [$this->approvalLabel($context, 'yes'), $this->approvalLabel($context, 'no')],
        );

        $this->hold($context, $pending);

        return StepResult::wait()->spoken();
    }

    /**
     * @return array{action: string, input: array<string, mixed>}|null
     */
    private function pending(StepContext $context): ?array
    {
        $pending = ((array) $context->conversation->meta)[$this->pendingKey($context)] ?? null;

        return is_array($pending) && is_string($pending['action'] ?? null)
            ? ['action' => $pending['action'], 'input' => (array) ($pending['input'] ?? [])]
            : null;
    }

    /**
     * Kept with the conversation, not in the answers bag, for the reason the
     * approval node keeps its counter there: it is bookkeeping about the
     * exchange, and a value offered to templates is a value somebody branches on.
     *
     * @param  array{action: string, input: array<string, mixed>}|null  $pending
     */
    private function hold(StepContext $context, ?array $pending): void
    {
        $meta = (array) $context->conversation->meta;

        if ($pending === null) {
            unset($meta[$this->pendingKey($context)]);
        } else {
            $meta[$this->pendingKey($context)] = $pending;
        }

        $context->conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }

    private function pendingKey(StepContext $context): string
    {
        return 'agent_approval:'.$context->step->key;
    }

    private function approvalLabel(StepContext $context, string $which): string
    {
        $fallback = (string) __('chatbot::nodes.steps.approval.'.$which);
        $label = trim((string) $context->setting($which === 'yes' ? 'approve_label' : 'decline_label', $fallback));

        return $label === '' ? $fallback : $label;
    }

    public static function key(): string
    {
        return 'ai_agent';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.ai_agent.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public function color(): string
    {
        return '#7c3aed';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Pair;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function pairLabels(): array
    {
        return array_values((array) __('chatbot::nodes.steps.ai_agent.exits'));
    }

    /**
     * What the visitor reads is composed by the model, so the node's body box
     * would be a fixed sentence in front of it whose effect the author cannot
     * see until a real conversation runs.
     */
    public function speaks(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['ask', 'say', 'handoff', 'close', 'condition'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('provider')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.provider.label'))
                ->default('')
                ->options(fn (): array => ['' => __('chatbot::nodes.steps.ai.fields.provider.default_option')] + $this->gateways->names())
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.provider.hint')),

            Field::textarea('instruction')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.instruction.label'))
                ->rows(8)
                ->required()
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.instruction.hint')),

            Field::select('tools')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.tools.label'))
                ->multiple()
                ->options(fn (): array => $this->toolOptions())
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.tools.hint')),

            Field::select('resources')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.resources.label'))
                ->multiple()
                ->options(fn (): array => $this->resourceOptions())
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.resources.hint')),

            Field::select('slots')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.slots.label'))
                ->multiple()
                ->options(fn (): array => $this->slotOptions())
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.slots.hint')),

            Field::select('requires')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.requires.label'))
                ->multiple()
                ->options(fn (): array => $this->slotOptions())
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.requires.hint')),

            Field::text('approve_label')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.approve_label.label'))
                ->default(__('chatbot::nodes.steps.approval.yes'))
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.approve_label.hint')),

            Field::text('decline_label')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.decline_label.label'))
                ->default(__('chatbot::nodes.steps.approval.no')),

            Field::textarea('approval_note')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.approval_note.label'))
                ->rows(2)
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.approval_note.hint')),

            Field::toggle('search')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.search.label'))
                ->default(true)
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.search.hint')),

            Field::toggle('call_human')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.call_human.label'))
                ->default(true)
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.call_human.hint')),

            Field::number('window')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.window.label'))
                ->default((int) config('chatbot.ai.agent.window', 12))
                ->min(2)
                ->max(60)
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.window.hint')),

            Field::number('rounds')
                ->label(__('chatbot::nodes.steps.ai_agent.fields.rounds.label'))
                ->default((int) config('chatbot.ai.agent.rounds', 4))
                ->min(1)
                ->max(8)
                ->hint(__('chatbot::nodes.steps.ai_agent.fields.rounds.hint')),
        ];
    }

    /**
     * The next message of a conversation the model is holding comes back here.
     *
     * Same work as `run()`: the answer is already in the context as what was
     * said this turn, and a second reading of it would be a second copy of the
     * same decision. What the override buys is the default not applying —
     * `StepType::resume()` answers `next()`, which would push the conversation
     * out of the agent's hands on the visitor's second message.
     */
    public function resume(StepContext $context, string $answer): StepResult
    {
        $pending = $this->pending($context);

        if ($pending === null) {
            return $this->run($context);
        }

        $this->hold($context, null);

        return $this->turn($context, $this->settle($context, $pending, $answer));
    }

    public function run(StepContext $context): StepResult
    {
        return $this->turn($context);
    }

    /**
     * Ответ на «подтверждаете?» — и выполнение действия, если это «да».
     *
     * **Неясный ответ — это не «да».** То же правило, что у узла подтверждения
     * (`ApprovalStep`), и по той же причине: «а можно без детей?» в ответ
     * на итог заявки — это правка, а не согласие, и заявка, заведённая
     * по нему, ушла бы менеджеру с детьми. Переспрашивать здесь не нужно:
     * модель прочтёт ответ сама и уточнит, что поменять.
     *
     * Действие выполняется только если узел всё ещё его предлагает: автор
     * мог снять галочку, пока человек думал над ответом.
     *
     * @param  array{action: string, input: array<string, mixed>}  $pending
     * @return array{aside: string, trace: array<string, mixed>}
     */
    private function settle(StepContext $context, array $pending, string $answer): array
    {
        $action = in_array($pending['action'], $this->chosen($context, 'tools'), true)
            ? $this->registry->capability($pending['action'])
            : null;

        $label = $action === null ? $pending['action'] : $action->label;

        if (ApprovalAnswer::verdict($answer, $this->approvalLabel($context, 'yes'), $this->approvalLabel($context, 'no')) !== true) {
            return [
                'aside' => __('chatbot::nodes.steps.ai_agent.tools.approval_declined', ['action' => $label]),
                'trace' => ['approval' => ['action' => $pending['action'], 'approved' => false]],
            ];
        }

        if ($action === null) {
            return [
                'aside' => __('chatbot::nodes.steps.ai_agent.tools.approval_failed', ['action' => $label, 'error' => 'not offered here any more']),
                'trace' => ['approval' => ['action' => $pending['action'], 'approved' => true, 'ok' => false]],
            ];
        }

        $result = $this->capabilities->run($action, (array) $pending['input'], $context);

        if (! $result->ok()) {
            return [
                'aside' => __('chatbot::nodes.steps.ai_agent.tools.approval_failed', ['action' => $label, 'error' => (string) $result->error()]),
                'trace' => ['approval' => ['action' => $pending['action'], 'approved' => true, 'ok' => false, 'reason' => (string) $result->error()]],
            ];
        }

        return [
            'aside' => __('chatbot::nodes.steps.ai_agent.tools.approval_done', [
                'action' => $label,
                'result' => (string) json_encode($result->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]),
            'trace' => ['approval' => ['action' => $pending['action'], 'approved' => true, 'ok' => true]],
        ];
    }

    /**
     * @param  array{aside: string, trace: array<string, mixed>}|null  $settled
     */
    private function turn(StepContext $context, ?array $settled = null): StepResult
    {
        $gateway = $this->gateways->guarded(
            $context->conversation->bot,
            (string) $context->setting('provider', ''),
        );

        if (! $gateway->isAvailable()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                'reason' => 'No model is available for this installation.',
            ]);
        }

        /*
         * No check here that the driver can actually hold a conversation, and
         * that is deliberate rather than an omission. Every gateway a
         * conversation may use comes wrapped in the budget
         * ({@see \MB\FilamentChatbot\Budgets\BudgetedGateway}) — which is the
         * point of the wrapper, since an agent is the most expensive caller in
         * the package and must not be able to reach a provider around it. The
         * wrapper therefore always satisfies this contract, and asking it
         * `instanceof` would be a branch that is dead by construction. It is
         * the wrapper that declines, naming the driver, and that reason reaches
         * the journal below.
         *
         * Where the question *can* be answered is the editor: `issues()` asks
         * the bare driver, so an author drawing the node is told before a
         * customer finds out.
         */
        $history = $this->history($context);

        if ($history === []) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node was reached with nothing said in the conversation yet.',
            ]);
        }

        $aside = implode("\n\n", array_filter(
            [$settled['aside'] ?? '', $this->captureContacts($context)],
            static fn (string $part): bool => $part !== '',
        ));

        $outcome = $this->loop->run(
            gateway: $gateway,
            instruction: $this->instruction($context, $aside === '' ? null : $aside),
            history: $history,
            tools: $this->tools($context),
            context: $context,
            rounds: (int) $context->setting('rounds', 4),
            stable: strlen($this->standing($context)),
        );

        $settledTrace = $settled['trace'] ?? [];

        if (! $outcome->ok) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                ...$settledTrace,
                ...$outcome->trace(),
            ]);
        }

        if ($outcome->pending !== null) {
            return $this->askApproval($context, $outcome->pending, $outcome->text)->traced(RunStep::WAITING, [
                'provider' => $gateway->name(),
                ...$settledTrace,
                ...$outcome->trace(),
            ]);
        }

        if ($outcome->text !== '') {
            $context->runtime->say($outcome->text);
        }

        $this->summarise($context, $gateway);

        if ($outcome->wantsHuman) {
            return StepResult::second()->traced(RunStep::PASSED, [
                'provider' => $gateway->name(),
                ...$settledTrace,
                ...$outcome->trace(),
            ])->spoken($outcome->text !== '');
        }

        /*
         * An ordinary answer leaves the conversation here, waiting for the next
         * message, and the first exit is taken only when the model says its
         * part is done.
         *
         * This was the other way round for one release, with a condition on the
         * canvas deciding — "once these values are filled, carry on" — and a
         * live conversation showed why it cannot be: the model asked "куда бы
         * Вы хотели поехать?", the visitor's previous message happened to
         * complete the list, and the scenario spoke over the question it had
         * just asked. The customer read a question and then a summary
         * answering a different one. Only the model knows whether it has just
         * asked something, so the hand-off is its decision.
         *
         * Waiting rather than ending the walk, so the next message comes back
         * to this node instead of being routed again: a conversation the model
         * is leading must not be taken from it mid-sentence by a scenario that
         * matched a keyword. Breaking out stays possible — it calls a person,
         * or it passes the conversation on.
         */
        if (! $outcome->passedOn) {
            return StepResult::wait()->traced(RunStep::SAID, [
                'provider' => $gateway->name(),
                ...$settledTrace,
                ...$outcome->trace(),
            ])->spoken();
        }

        return StepResult::next()->traced(RunStep::PASSED, [
            'provider' => $gateway->name(),
            ...$settledTrace,
            ...$outcome->trace(),
        ])->spoken($outcome->text !== '');
    }

    /**
     * Rewrites the running summary once a windowful of conversation has
     * scrolled out of sight.
     *
     * **Not on every turn**, which is the whole design: a summary call is a
     * second billed request, and refreshing it after every message would
     * double the cost of the agent for a sentence that changes once an hour.
     * Triggered by a window's worth of lines having fallen out, it runs once
     * per that many messages — roughly once per ten in a default setup.
     *
     * Asked through `ask()` rather than `converse()` on purpose: this is a
     * single question with a single answer, the tools have no business being
     * in it, and it goes through the same budget as everything else.
     *
     * A failure is silent, and deliberately so. Nothing the visitor asked for
     * depends on it: the answer has already been spoken by the time this runs,
     * the window still carries the recent exchange, and the only cost of a
     * provider refusing here is a summary that stays as it was until the next
     * time a windowful falls out. Reporting it would turn a conversation that
     * went well into a failed node in the journal.
     */
    private function summarise(StepContext $context, AiGateway $gateway): void
    {
        $window = (int) $context->setting('window', 12);
        $fallen = $this->memory->fallenOut($context->conversation, $window);

        if (count($fallen['lines']) < $window || $fallen['until'] === 0) {
            return;
        }

        $known = $this->memory->summary($context->conversation);

        $reply = $gateway->ask(new AiPrompt(
            instruction: __('chatbot::nodes.steps.ai_agent.summary_instruction'),
            question: implode("\n", array_filter([
                $known === null ? null : __('chatbot::nodes.steps.ai_agent.summary_known')."\n".$known,
                __('chatbot::nodes.steps.ai_agent.summary_new')."\n".implode("\n", $fallen['lines']),
            ])),
            maxTokens: 400,
        ));

        if (! $reply->ok() || trim($reply->text()) === '') {
            return;
        }

        $this->memory->remember($context->conversation, $reply->text(), $fallen['until']);
    }

    /**
     * The exchange the model is shown: what is in the window, plus this turn's
     * message when the window has not caught it yet.
     *
     * The visitor's own last message is appended rather than assumed present,
     * because a walk can reach this node before the incoming row is visible to
     * a fresh query — and a turn whose final message is the bot's own leaves
     * the model answering the question before last.
     *
     * @return list<AiMessage>
     */
    private function history(StepContext $context): array
    {
        $window = $this->memory->window($context->conversation, (int) $context->setting('window', 12));
        $said = $this->redactor->clean($context->said);

        if ($said === '') {
            return $window;
        }

        $last = $window === [] ? null : $window[count($window) - 1];

        if ($last instanceof AiMessage && $last->role === AiMessage::VISITOR && $last->text === $said) {
            return $window;
        }

        return [...$window, AiMessage::fromVisitor($said)];
    }

    /**
     * The node's instruction, with what is known from earlier under it — and
     * what is still not written down.
     *
     * The summary goes in the instruction rather than into the exchange as a
     * message, because it is not something anybody said: inserted as a visitor
     * line it becomes something the model may quote back, and as a bot line it
     * becomes something the model thinks it already told them.
     *
     * **The list of what is still missing is here for a failure seen live.**
     * A model says a value out loud — "двое взрослых и дети 5 и 9 лет" — and
     * does not write it: it has answered the person, so as far as it is
     * concerned the matter is closed. The gate on `pass_on` would tell it, but
     * only once it tries to hand over, and a model still happily asking about
     * beach preferences never tries. Computed from the runtime every turn, this
     * line is never out of date and never a reminder of something already
     * written — which is what makes it worth the tokens.
     */
    private function instruction(StepContext $context, ?string $aside = null): string
    {
        $parts = [$this->standing($context)];

        if ($aside !== null) {
            $parts[] = $aside;
        }

        $summary = $this->memory->summary($context->conversation);
        $missing = [];

        foreach ($this->chosen($context, 'requires') as $field) {
            if (trim((string) $context->runtime->value($field)) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $parts[] = __('chatbot::nodes.steps.ai_agent.still_missing', ['fields' => implode(', ', $missing)]);
        }

        if ($summary !== null) {
            $parts[] = __('chatbot::nodes.steps.ai_agent.summary_header')."\n".$summary;
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Writes down a phone number or an email the visitor just typed, and tells
     * the model it was written — without the value.
     *
     * Seen live: a customer typed their number three times, the model saw
     * "[телефон]" every time, asked again twice and then fetched a manager.
     * The redactor is right to keep the number from the provider, and the model
     * can only write down what it is shown — so the package writes it, into
     * the slots this node may fill, under the names in
     * `chatbot.ai.agent.contact_slots`.
     */
    private function captureContacts(StepContext $context): string
    {
        $found = $this->redactor->contacts($context->said);
        $slots = $this->chosen($context, 'slots');
        $written = [];

        foreach ((array) config('chatbot.ai.agent.contact_slots', ['phone' => 'phone', 'email' => 'email']) as $kind => $slot) {
            $value = $found[$kind][0] ?? null;

            if ($value === null || ! is_string($slot) || ! in_array($slot, $slots, true)) {
                continue;
            }

            $this->loop->write($slot, $value, $context);
            $written[] = $slot;
        }

        return $written === []
            ? ''
            : __('chatbot::nodes.steps.ai_agent.contact_captured', ['fields' => implode(', ', $written)]);
    }

    /**
     * The part of the instruction that is the same on every turn: the bot's own
     * system prompt, then this node's text.
     *
     * The bot's prompt field promised "sent to the model on every turn" and no
     * node read it — an agency wrote who the bot is and how it speaks, and the
     * model never saw a word of it.
     */
    private function standing(StepContext $context): string
    {
        return implode("\n\n", array_filter(
            [trim((string) $context->conversation->bot?->prompt), trim($context->filled('instruction'))],
            static fn (string $part): bool => $part !== '',
        ));
    }

    private function tools(StepContext $context): AgentTools
    {
        $actions = [];

        foreach ($this->chosen($context, 'tools') as $key) {
            $action = $this->registry->capability($key);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        $slots = $this->chosen($context, 'slots');

        return AgentTools::of(
            actions: $actions,
            canSearch: (bool) $context->setting('search', true),
            canRemember: $slots !== [],
            canCallHuman: (bool) $context->setting('call_human', true),
            slots: $slots,
            resources: $this->resources($context),
            canPassOn: filled($context->step->next_key),
            requires: $this->chosen($context, 'requires'),
        );
    }

    /**
     * The declared resources this node lets the model read.
     *
     * Only the active ones, and only by the keys the author chose: a resource
     * switched off in the panel is switched off for the model too, without the
     * author having to remember to un-choose it here.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    private function resources(StepContext $context): array
    {
        $keys = $this->chosen($context, 'resources');

        if ($keys === []) {
            return [];
        }

        return DataResource::query()
            ->whereIn('key', $keys)
            ->where('is_active', true)
            ->get(['key', 'label', 'description'])
            ->map(static fn (DataResource $resource): array => [
                'key' => (string) $resource->key,
                'label' => (string) $resource->label,
                'description' => (string) $resource->description,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function chosen(StepContext $context, string $name): array
    {
        $values = $context->setting($name, []);

        if (is_string($values)) {
            $values = $values === '' ? [] : [$values];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    private function toolOptions(): array
    {
        $options = [];

        foreach ($this->registry->capabilities() as $key => $action) {
            $options[$key] = $action->effect === SideEffect::Write
                ? $action->label.' — '.__('chatbot::nodes.steps.ai_agent.writes')
                : $action->label;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function resourceOptions(): array
    {
        return DataResource::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->pluck('label', 'key')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function slotOptions(): array
    {
        $options = [];

        foreach ($this->registry->slots() as $slot) {
            $options[$slot->key()] = $slot->name();
        }

        return $options;
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('instruction', '')) === '') {
            $issues[] = [
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.ai_agent.issues.no_instruction'),
            ];
        }

        $gateway = $this->gateways->make((string) $step->setting('provider', ''));

        if (! $gateway->isAvailable()) {
            $issues[] = [
                'severity' => 'warning',
                'message' => __('chatbot::nodes.steps.ai_agent.issues.no_model'),
            ];
        } elseif (! $gateway instanceof AiConversationGateway) {
            $issues[] = [
                'severity' => 'blocking',
                'message' => __('chatbot::nodes.steps.ai_agent.issues.driver_cannot_converse', ['driver' => $gateway->name()]),
            ];
        }

        /*
         * A write handed to a model is said on the canvas, while the scenario
         * is open and the fix is cheap. The release check behind
         * `ScenarioGraph::writes()` is about an approval node standing above a
         * write; here there is no node to stand above, because the decision to
         * call it is the model's — so the author is told which of the actions
         * they offered can change data.
         */
        foreach ($this->offered($step) as $action) {
            if ($action->effect === SideEffect::Write || $action->requiresApproval) {
                $issues[] = [
                    'severity' => 'notice',
                    'message' => __('chatbot::nodes.steps.ai_agent.issues.model_may_write', ['action' => $action->label]),
                ];
            }
        }

        return $issues;
    }

    /**
     * The actions a released version of this node is bound to, versions and all.
     *
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        $references = [];

        foreach ($this->offered($step) as $action) {
            $references[] = $action->reference();
        }

        foreach ($this->missing($step) as $key) {
            $references[] = $key.'@?';
        }

        return $references === [] ? [] : ['capabilities' => $references];
    }

    /**
     * @return array<string, CapabilityAction>
     */
    private function offered(Step $step): array
    {
        $actions = [];

        foreach ($this->keys($step) as $key) {
            $action = $this->registry->capability($key);

            if ($action !== null) {
                $actions[$key] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function missing(Step $step): array
    {
        return array_values(array_filter(
            $this->keys($step),
            fn (string $key): bool => $this->registry->capability($key) === null,
        ));
    }

    /**
     * @return list<string>
     */
    private function keys(Step $step): array
    {
        $values = $step->setting('tools', []);

        if (is_string($values)) {
            $values = $values === '' ? [] : [$values];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $values),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
