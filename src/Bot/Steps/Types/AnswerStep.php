<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Knowledge\KnowledgeFinder;
use MB\FilamentChatbot\Knowledge\KnowledgeVerdict;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Answers the visitor's last message from the knowledge base, or admits it cannot.
 *
 * The node exists so that a scenario does not have to enumerate the questions
 * people ask. What it may say is decided by the topic it finds and not by this
 * node: the mode on the topic is the company's standing decision about that
 * subject, and it has to survive being reached from any scenario anybody writes.
 *
 * The second output means "no answer was given" for every reason at once — no
 * topic matched, two matched equally, or the topic was one the bot may not speak
 * about. They are told apart in the trace rather than by extra arrows, because
 * an author wires all of them to the same place: a person.
 */
final class AnswerStep extends StepType
{
    public function __construct(private readonly KnowledgeFinder $finder) {}

    public static function key(): string
    {
        return 'answer';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.answer.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-book-open';
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
        return array_values((array) __('chatbot::nodes.steps.answer.exits'));
    }

    /**
     * The node says something and still reports that it does not.
     *
     * speaks() is about the node's *own* body text, which this node has none of:
     * every word it sends comes from the topic it found. Answering true would
     * make the editor draw a message box on the node, and whatever an author
     * wrote in it would be sent ahead of the real answer — the visitor reads two
     * replies to one question, the first of which is usually blank, and nothing
     * on the canvas explains where the empty line came from.
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
        return ['handoff', 'close', 'say', 'ask'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('category')
                ->label(__('chatbot::nodes.steps.answer.fields.category.label'))
                ->options(fn (): array => $this->categories())
                ->hint(__('chatbot::nodes.steps.answer.fields.category.hint')),

            Field::textarea('fallback')
                ->label(__('chatbot::nodes.steps.answer.fields.fallback.label'))
                ->rows(2)
                ->hint(__('chatbot::nodes.steps.answer.fields.fallback.hint')),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $verdict = $this->finder->find(
            $context->conversation->bot_id === null ? null : (int) $context->conversation->bot_id,
            $context->said,
            $this->category($context),
        );

        if ($verdict === null) {
            return StepResult::second()->traced(RunStep::UNKNOWN, ['said' => $context->said]);
        }

        $entry = $verdict->entry();

        return match ($entry->mode) {
            KnowledgeMode::Handoff => $this->withheld($verdict),
            KnowledgeMode::Urgent => $this->urgent($verdict),
            KnowledgeMode::Scenario => $this->toScenario($verdict),
            default => $this->answer($context, $verdict),
        };
    }

    /**
     * A topic the bot found and must not speak about.
     *
     * **The text is never sent, and there is deliberately no setting that would
     * let it be.** These topics are refunds, prices, deadlines and house rules —
     * the answers a customer repeats back as a promise the company then has to
     * keep. A sentence that was true last quarter is still stored, still matches
     * the question perfectly, and is now a commitment nobody made. Withholding
     * costs one handover; sending costs an argument the company loses.
     */
    private function withheld(KnowledgeVerdict $verdict): StepResult
    {
        return StepResult::second()->traced(RunStep::UNKNOWN, $this->trace($verdict, [
            'withheld' => true,
        ]));
    }

    /**
     * A topic that may not wait in a queue.
     *
     * Routed to a person here rather than through the second exit, because the
     * second exit is an ordinary "I do not know" that an author is free to wire
     * into another question or a polite goodbye. Urgent topics exist precisely
     * to cut that short.
     */
    private function urgent(KnowledgeVerdict $verdict): StepResult
    {
        return StepResult::handoff()->traced(RunStep::HANDED_OFF, $this->trace($verdict, [
            'urgent' => true,
        ]));
    }

    /**
     * A topic whose useful reply is a scenario.
     *
     * A mode with no slug is a topic that leads nowhere. It takes the second
     * exit rather than the jump, and it is traced as a fault rather than as "not
     * understood": nothing about the visitor's wording caused it, and it is
     * fixed in the knowledge base and nowhere else.
     */
    private function toScenario(KnowledgeVerdict $verdict): StepResult
    {
        $slug = trim((string) $verdict->entry()->scenario_slug);

        if ($slug === '') {
            return StepResult::second()->traced(RunStep::FAILED, $this->trace($verdict, [
                'reason' => 'The topic starts a scenario but does not say which one.',
            ]));
        }

        return StepResult::jump($slug)->traced(RunStep::JUMPED, $this->trace($verdict, [
            'scenario' => $slug,
        ]));
    }

    /**
     * A topic the bot may repeat.
     *
     * Verify is answered exactly like Answer and flagged in the trace instead.
     * Holding it back would empty the knowledge base of everything seasonal,
     * and prefixing the reply with a warning teaches visitors to distrust the
     * bot on the topics it is right about most of the time. The flag is for the
     * person reading the journal, who is the one who can check.
     */
    private function answer(StepContext $context, KnowledgeVerdict $verdict): StepResult
    {
        $entry = $verdict->entry();
        $text = trim((string) $entry->answer);

        if ($text === '') {
            $text = trim($context->filled('fallback'));
        }

        if ($text === '') {
            return StepResult::second()->traced(RunStep::UNKNOWN, $this->trace($verdict, [
                'reason' => 'The topic matched but carries no text to send.',
            ]));
        }

        $context->runtime->say($text);

        return StepResult::next()
            ->traced(RunStep::ANSWERED, $this->trace($verdict, [
                'needs_check' => $entry->mode === KnowledgeMode::Verify,
            ]))
            ->spoken();
    }

    /**
     * What the journal records about a match, whichever way it went.
     *
     * The runners-up are in here for the same reason they are on the verdict: a
     * complaint about a wrong answer is investigated by asking what came second,
     * and by the time anybody asks, the visitor's exact wording is gone.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function trace(KnowledgeVerdict $verdict, array $extra = []): array
    {
        return array_merge([
            'topic' => $verdict->entry()->topic_key,
            'mode' => $verdict->entry()->mode->value,
            /*
             * Which pass found it, beside the score. The two score on different
             * scales — a weighted share of the question's roots against a
             * cosine between letter pieces — so a number recorded without it is
             * a number nobody reading the journal afterwards can interpret.
             */
            'via' => $verdict->via(),
            'score' => round($verdict->score(), 4),
            'roots' => $verdict->roots(),
            'alternatives' => $verdict->alternatives(),
        ], $extra);
    }

    private function category(StepContext $context): ?string
    {
        $category = trim((string) $context->setting('category', ''));

        return $category === '' ? null : $category;
    }

    /**
     * The sections that exist, so an author picks one instead of spelling it.
     *
     * A typed category that matches nothing turns the node into one that never
     * answers, and nothing on the canvas would say why.
     *
     * @return array<string, string>
     */
    private function categories(): array
    {
        return KnowledgeEntry::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category', 'category')
            ->all();
    }
}
