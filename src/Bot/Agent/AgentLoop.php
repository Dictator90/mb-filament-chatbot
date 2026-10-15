<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Agent;

use MB\FilamentChatbot\Ai\AiConversationGateway;
use MB\FilamentChatbot\Ai\AiMessage;
use MB\FilamentChatbot\Ai\AiToolCall;
use MB\FilamentChatbot\Ai\AiTurn;
use MB\FilamentChatbot\Ai\AiUsage;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Capabilities\CapabilityGateway;
use MB\FilamentChatbot\Chat\ConversationFacts;
use MB\FilamentChatbot\Data\DataQuery;
use MB\FilamentChatbot\Data\DataReader;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\DataQueryMode;

/**
 * Lets the model lead one turn of the conversation: ask for things, read what
 * came back, and then answer.
 *
 * **Why a class of its own rather than the body of a node.** The node belongs
 * to the canvas — settings, two exits, what the editor shows. This is the part
 * that has to be reasoned about on its own terms: how many times a model may be
 * asked before the turn is abandoned, what happens to a tool the author never
 * offered, who checks the arguments, and in what order the three things that
 * can end a turn are decided. Every one of those has a wrong answer that works
 * in a demo.
 *
 * **Rounds are capped and the cap is low.** A model that cannot finish is a
 * model that asks for the same thing again, and each round is a billed request
 * carrying the whole history. Uncapped, one confused turn spends a month's
 * budget while the visitor watches nothing happen; capped, it ends in the
 * node's second exit, which the author has already wired to a person.
 *
 * **A tool that was not offered is refused and reported back to the model**
 * rather than ending the turn. Models do invent a tool — usually a sensible
 * one, which is the trap: `cancel_booking` sounds like something a travel bot
 * would have. Answering "there is no such tool" lets it recover in the same
 * turn and say something honest; aborting would show the visitor a silence
 * caused by a tool nobody ever wrote.
 *
 * **Nothing is said to the visitor until the model has finished.** A model
 * usually says a sentence alongside its request — "сейчас посмотрю" — and
 * speaking those would give the visitor two or three messages per answer, in a
 * channel where each one is a notification. The sentence is kept for the
 * journal; what the visitor reads is the answer the model gave after seeing
 * what the tools returned.
 */
final class AgentLoop
{
    public function __construct(
        private readonly CapabilityGateway $capabilities,
        private readonly ConversationFacts $facts,
        private readonly DataReader $data,
        private readonly ?KnowledgeSource $knowledge = null,
    ) {}

    /**
     * @param  list<AiMessage>  $history
     */
    public function run(
        AiConversationGateway $gateway,
        string $instruction,
        array $history,
        AgentTools $tools,
        StepContext $context,
        int $rounds = 4,
        int $candidates = 5,
    ): AgentOutcome {
        $turn = new AiTurn($instruction, $history, $tools->catalogue());
        $usage = new AiUsage;
        $did = [];
        $rounds = max(1, min($rounds, 8));

        for ($round = 1; $round <= $rounds; $round++) {
            $reply = $gateway->converse($turn);
            $usage = $this->add($usage, $reply->usage());

            if (! $reply->ok()) {
                return AgentOutcome::failed(
                    $reply->error() ?? 'The model declined to answer.',
                    $usage,
                    $did,
                    $round,
                );
            }

            if (! $reply->wantsTools()) {
                $text = trim($reply->text());

                return $text === ''
                    ? AgentOutcome::failed('The model answered with nothing.', $usage, $did, $round)
                    : AgentOutcome::spoke($text, $usage, $did, $round);
            }

            $turn = $turn->with(AiMessage::asking($reply->calls(), $reply->text()));

            foreach ($reply->calls() as $call) {
                if ($call->name === AgentTools::PASS) {
                    $did[] = ['tool' => $call->name, 'ok' => true];

                    return AgentOutcome::passedOn(
                        trim((string) ($call->input['note'] ?? '')),
                        $reply->text(),
                        $usage,
                        $did,
                        $round,
                    );
                }

                if ($call->name === AgentTools::HUMAN) {
                    $did[] = ['tool' => $call->name, 'ok' => true];

                    return AgentOutcome::wantsHuman(
                        trim((string) ($call->input['reason'] ?? '')),
                        $reply->text(),
                        $usage,
                        $did,
                        $round,
                    );
                }

                $done = $this->perform($call, $tools, $context, $candidates);

                $did[] = array_filter([
                    'tool' => $call->name,
                    'ok' => $done['ok'],
                    'reason' => $done['ok'] ? null : ($done['reason'] ?? $done['text']),
                ], static fn (mixed $value): bool => $value !== null);

                $turn = $turn->with(AiMessage::result($call->id, $done['text'], $done['ok']));
            }
        }

        return AgentOutcome::failed(
            'The model kept asking for tools and never answered.',
            $usage,
            $did,
            $rounds,
        );
    }

    /**
     * Carries out one request and words the answer for the model.
     *
     * The answer is prose rather than JSON because that is what the model reads
     * best, and because a tool result is not a contract: what is on the other
     * side of it is a sentence, and a shape invented here would be a second
     * description of the host's own data that nothing keeps in step.
     *
     * @return array{text: string, ok: bool, reason?: string}
     */
    private function perform(AiToolCall $call, AgentTools $tools, StepContext $context, int $candidates): array
    {
        if ($call->name === AgentTools::SEARCH) {
            return $this->search($call, $context, $candidates);
        }

        if ($call->name === AgentTools::REMEMBER) {
            return $this->remember($call, $tools, $context);
        }

        $resource = $tools->resource($call->name);

        if ($resource !== null) {
            return $this->read($resource, $call, $context);
        }

        $action = $tools->action($call->name);

        if ($action === null) {
            return [
                'ok' => false,
                'text' => "There is no tool called [{$call->name}] in this conversation. Carry on without it.",
            ];
        }

        $result = $this->capabilities->run($action, $call->input, $context);

        if (! $result->ok()) {
            return ['ok' => false, 'text' => (string) $result->error()];
        }

        $data = $result->data();

        return [
            'ok' => true,
            'text' => $data === []
                ? 'Done.'
                : 'Done. '.(string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Reads a declared resource — through the reader, like everything else.
     *
     * Nothing about the query comes from the model except how many rows: the
     * resource is one the author allow-listed, the fields are the ones it
     * declared returnable, the sensitive ones are dropped by the reader, and
     * the scope that narrows the rows to this person is resolved from server
     * state where neither the model nor the visitor can reach it. A second road
     * to the same columns — a host action that selects them itself — would have
     * none of those rules, which is why the reader is the only road.
     *
     * **A refusal is not repeated to the model in the reader's words.** Those
     * name the resource, the field and the limit that was too high; handed to a
     * model they end up quoted at a customer as an explanation of why their
     * trip could not be found. The model is told it cannot see the data; the
     * real sentence goes to the journal.
     *
     * @return array{text: string, ok: bool, reason?: string}
     */
    private function read(string $resource, AiToolCall $call, StepContext $context): array
    {
        $limit = (int) ($call->input['limit'] ?? 0);

        $result = $this->data->read(
            new DataQuery(
                resource: $resource,
                mode: DataQueryMode::List,
                limit: $limit > 0 ? min($limit, 20) : null,
            ),
            RuntimeContext::forConversation($context->conversation),
        );

        if (! $result->ok()) {
            return [
                'ok' => false,
                'text' => __('chatbot::nodes.steps.ai_agent.tools.read_refused'),
                'reason' => (string) $result->error(),
            ];
        }

        if ($result->isEmpty()) {
            return ['ok' => true, 'text' => __('chatbot::nodes.steps.ai_agent.tools.read_empty')];
        }

        $lines = [];

        foreach ($result->rows() as $index => $row) {
            $fields = [];

            foreach ($row as $name => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $fields[] = $name.': '.$value;
            }

            $lines[] = ($index + 1).') '.implode(', ', $fields);
        }

        return ['ok' => true, 'text' => implode("\n", $lines)];
    }

    /**
     * @return array{text: string, ok: bool}
     */
    private function search(AiToolCall $call, StepContext $context, int $candidates): array
    {
        $query = trim((string) ($call->input['query'] ?? ''));

        if ($query === '' || $this->knowledge === null) {
            return ['ok' => false, 'text' => 'Nothing to search: no query, or this installation has no knowledge base.'];
        }

        $found = $this->knowledge->candidates($context->conversation->bot_id, $query, max(1, min($candidates, 20)));

        if ($found === []) {
            return ['ok' => true, 'text' => 'The agency material has nothing on that. Say so rather than answering from elsewhere.'];
        }

        $blocks = [];

        foreach ($found as $fragment) {
            $blocks[] = trim((string) ($fragment['title'] ?? ''))."\n".trim((string) ($fragment['text'] ?? ''));
        }

        return ['ok' => true, 'text' => implode("\n\n", $blocks)];
    }

    /**
     * Writes one value where the rest of the bot can read it.
     *
     * Two places, and both are needed. The runtime's own store is what a
     * condition, a template and the next node read — that is the interview
     * being kept. `ConversationFacts` is what the *desk* reads: a name or a
     * phone number learned by the model and written only to a variable leaves
     * the operator looking at "Conversation #41" with the person's name three
     * lines below it in the transcript.
     *
     * @return array{text: string, ok: bool}
     */
    private function remember(AiToolCall $call, AgentTools $tools, StepContext $context): array
    {
        $field = trim((string) ($call->input['field'] ?? ''));
        $value = trim((string) ($call->input['value'] ?? ''));

        if ($field === '' || $value === '') {
            return ['ok' => false, 'text' => 'Both a field and a value are needed.'];
        }

        if (! $tools->mayRemember($field)) {
            return [
                'ok' => false,
                'text' => "[{$field}] is not one of the things this conversation keeps. Allowed: "
                    .implode(', ', $tools->slots()).'.',
            ];
        }

        $context->runtime->store($field, $value);
        $this->facts->remember($context->conversation, $field, $value);

        return ['ok' => true, 'text' => "Remembered {$field}."];
    }

    private function add(AiUsage $total, AiUsage $one): AiUsage
    {
        return new AiUsage(
            $total->inputTokens + $one->inputTokens,
            $total->outputTokens + $one->outputTokens,
            $total->costMicro + $one->costMicro,
        );
    }
}
