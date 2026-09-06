<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Ai\NullKnowledgeSource;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Answers the visitor with a model, from the material this installation gave it.
 *
 * **A node of its own, not a switch inside the knowledge answer, and that is
 * the decision this class exists to record.** While the model lived inside that
 * node, the canvas showed one box for two completely different things: a
 * paragraph the agency wrote and approved, and a sentence composed on the spot
 * by somebody else's program. Whoever drew the scenario could not see which was
 * which, and the reviewer reading it could not either. Worse, the choice was
 * made once for the whole bot by a setting — so a company that wants a model
 * for opening hours and a fixed text for cancellation terms had no way to say
 * so. Two boxes, and the branch is chosen where it belongs: on the canvas, per
 * branch, by the person who is accountable for the wording.
 *
 * Two exits, and the second is taken far more often than a demo suggests: no
 * driver configured, no key, the library not installed, the provider down or
 * refusing, the budget spent, the answer unreadable, no material found, or an
 * answer that named no material at all. Every one of those leads to the same
 * place — a written reply or a person — and a scenario drawn without that exit
 * connected is a scenario that goes silent the first time a provider hiccups.
 *
 * **An answer that names no given source is not spoken.** The model is handed
 * labelled fragments and asked to say which it used; a claim naming something
 * outside that set is a fragment it made up, and an answer built on a made-up
 * fragment is the kind that sounds exactly like a real one. Refusing it costs a
 * "could not" branch; accepting it costs a customer being told the wrong refund
 * policy in the company's own voice.
 */
final class AiStep extends StepType
{
    private readonly KnowledgeSource $knowledge;

    /**
     * The knowledge source is optional so that the package works before a host
     * has bound one: with no material, every conversation reaching this node
     * takes the "could not" exit, which is the same branch as every other way
     * this node fails to answer.
     */
    public function __construct(
        private readonly AiGatewayFactory $gateways,
        ?KnowledgeSource $knowledge = null,
    ) {
        $this->knowledge = $knowledge ?? new NullKnowledgeSource;
    }

    public static function key(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return 'Answer with a model';
    }

    public function icon(): string
    {
        return 'heroicon-o-sparkles';
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
        return ['answered', 'could not'];
    }

    /**
     * The node's own body text is never sent.
     *
     * What the visitor reads is composed by the model, and a body box here
     * would put a fixed sentence in front of it that the author cannot see the
     * effect of until a real conversation runs.
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
        return ['ask', 'say', 'handoff', 'close'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('provider')
                ->label('Model')
                ->default('')
                ->options(fn (): array => ['' => 'The one this installation is set to'] + $this->gateways->names())
                ->hint('Leave as it is unless this particular answer needs a different provider.'),

            Field::textarea('instruction')
                ->label('How to answer')
                ->rows(5)
                ->default((string) config('chatbot.ai.answer.instruction', ''))
                ->hint('The model is told this before it sees the question. It only ever answers from the material found for the message.'),

            Field::number('candidates')
                ->label('How many articles to offer the model')
                ->default((int) config('chatbot.ai.answer.candidates', 5))
                ->min(1)
                ->max(20)
                ->hint('More material means a better chance of an answer and a larger, slower, costlier request.'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $said = trim((string) ($context->said ?? ''));

        if ($said === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node was reached without anything for the model to answer.',
            ]);
        }

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

        $candidates = $this->knowledge->candidates(
            $context->conversation->bot_id,
            $said,
            $this->limit($context),
        );

        if ($candidates === []) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'provider' => $gateway->name(),
                'reason' => 'Nothing in the knowledge base looked related to the message.',
            ]);
        }

        $prompt = new AiPrompt(
            instruction: $this->instruction($context),
            question: $said,
            context: $candidates,
        );

        $reply = $gateway->ask($prompt);

        if (! $reply->ok()) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                'failure' => $reply->failure()?->value,
                'reason' => $reply->error(),
                'usage' => $reply->usage()->toArray(),
            ]);
        }

        $used = array_values(array_intersect($reply->sources(), $prompt->contextKeys()));
        $text = trim($reply->text());

        if ($used === [] || $text === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'provider' => $gateway->name(),
                'claimed' => $reply->sources(),
                'offered' => $prompt->contextKeys(),
                'reason' => $text === ''
                    ? 'The model answered with nothing.'
                    : 'The model did not answer from the material it was given.',
                'usage' => $reply->usage()->toArray(),
            ]);
        }

        $context->runtime->say($text);

        return StepResult::next()->traced(RunStep::SAID, [
            'provider' => $gateway->name(),
            'sources' => $used,
            'usage' => $reply->usage()->toArray(),
        ])->spoken();
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('instruction', '')) === '') {
            $issues[] = [
                'severity' => 'warning',
                'message' => 'This node does not tell the model how to answer, so the reply will be in whatever voice the provider defaults to.',
            ];
        }

        /*
         * Said while the scenario is open rather than discovered by a customer:
         * with no model configured this node can only ever take its second
         * exit, and an unconnected second exit is a conversation ending in
         * silence.
         */
        if (! $this->gateways->make((string) $step->setting('provider', ''))->isAvailable()) {
            $issues[] = [
                'severity' => 'warning',
                'message' => 'No model is configured, so this node will always take its "could not" exit. Make sure that exit leads somewhere.',
            ];
        }

        return $issues;
    }

    private function instruction(StepContext $context): string
    {
        $instruction = trim($context->filled('instruction'));

        return $instruction === ''
            ? (string) config('chatbot.ai.answer.instruction', '')
            : $instruction;
    }

    private function limit(StepContext $context): int
    {
        $limit = (int) $context->setting('candidates', 0);

        if ($limit < 1) {
            $limit = (int) config('chatbot.ai.answer.candidates', 5);
        }

        return max(1, min($limit, 20));
    }
}
