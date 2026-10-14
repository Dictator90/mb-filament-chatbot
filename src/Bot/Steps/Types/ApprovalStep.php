<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Step;

/**
 * Asks for a yes before something that cannot be taken back.
 *
 * **An answer this node does not understand is a refusal.** That single rule is
 * the whole reason it exists as a node of its own rather than as one more
 * question with two buttons. Everywhere else in a scenario an unparsed answer
 * can be guessed at, and guessing wrong costs a clumsy sentence. Here it is
 * placed in front of cancelling an order, writing to the host's records,
 * charging somebody: a bot that reads "hmm, I'm not sure" or a mistyped word as
 * "probably yes" performs the irreversible thing on behalf of a person who was
 * hesitating. The two mistakes are not comparable — asking again, or handing
 * over to a human, costs a minute; a cancellation nobody asked for costs the
 * customer.
 *
 * So the attempts run out into the "declined" exit rather than into the
 * approved one, and there is no third exit for "did not understand": a scenario
 * that branched on it would eventually route it somewhere convenient, and the
 * convenient place is the one this node was put there to block.
 *
 * The visitor is asked again while attempts remain, because the common
 * unparsed answer is a question back ("what happens to my deposit?") rather
 * than a refusal, and hanging up on that is its own kind of rude.
 */
final class ApprovalStep extends StepType
{
    public static function key(): string
    {
        return 'approval';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.approval.label');
    }

    /**
     * The wording used when the answer was neither a yes nor a no.
     *
     * Methods rather than constants now that these are translated: a constant
     * is resolved at class-load time, and a worker serving one request in
     * Russian would go on answering every later conversation with whatever
     * language it happened to load with.
     */
    private function retry(): string
    {
        return __('chatbot::nodes.steps.approval.retry');
    }

    /**
     * @return list<string>
     */
    private function yesWords(): array
    {
        return array_values((array) __('chatbot::bot.approval.yes'));
    }

    /**
     * @return list<string>
     */
    private function noWords(): array
    {
        return array_values((array) __('chatbot::bot.approval.no'));
    }

    public function icon(): string
    {
        return 'heroicon-o-shield-check';
    }

    public function color(): string
    {
        return '#b45309';
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
        return array_values((array) __('chatbot::nodes.steps.approval.exits'));
    }

    public function speaks(): bool
    {
        return true;
    }

    public function waitsForReply(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'close', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::text('yes_label')
                ->label(__('chatbot::nodes.steps.approval.fields.yes_label.label'))
                ->default(__('chatbot::nodes.steps.approval.yes')),

            Field::text('no_label')
                ->label(__('chatbot::nodes.steps.approval.fields.no_label.label'))
                ->default(__('chatbot::nodes.steps.approval.no')),

            Field::number('attempts')
                ->label(__('chatbot::nodes.steps.approval.fields.attempts.label'))
                ->default(2)
                ->min(1)
                ->max(5)
                ->hint(__('chatbot::nodes.steps.approval.fields.attempts.hint')),
        ];
    }

    /**
     * «Да» и «нет» — такие же варианты ответа, как у вопроса с кнопками.
     *
     * Без этого подтверждение уходило в мессенджер простым текстом
     * «1. Да, оформляйте / 2. Нет, поправлю»: кнопок не было, человек отвечал
     * словами — «Оформляйте. Вы можете отель подобрать?» — и получал «не понял
     * ответ» на согласие, которое только что дал. Теперь варианты едут
     * свойством сообщения, и канал рисует их как умеет: Telegram кнопками,
     * виджет подсказками, а канал без кнопок — тем же нумерованным списком.
     *
     * @return list<string>
     */
    public function choiceLabels(Step $step): array
    {
        return [$this->labelOf($step, 'yes_label', 'yes'), $this->labelOf($step, 'no_label', 'no')];
    }

    public function run(StepContext $context): StepResult
    {
        $this->rememberAttempts($context, null);

        /*
         * Текст узла уже произнёс движок — вместе с вариантами. Повторить их
         * отдельной репликой значило бы прислать второе сообщение подряд,
         * состоящее из списка того, что нарисовано кнопками выше. Пусто только
         * у узла без текста: там список и есть весь вопрос.
         */
        if (trim($context->runtime->fill($context->step->body)) === '') {
            $context->runtime->say($this->options($context), $this->choiceLabels($context->step));
        }

        return StepResult::wait()->traced(RunStep::WAITING)->spoken();
    }

    public function resume(StepContext $context, string $answer): StepResult
    {
        $verdict = $this->verdict($context, $answer);

        if ($verdict === true) {
            $this->rememberAttempts($context, null);

            return StepResult::next()->traced(RunStep::APPROVED, ['answer' => $answer]);
        }

        if ($verdict === false) {
            $this->rememberAttempts($context, null);

            return StepResult::second()->traced(RunStep::DECLINED, ['answer' => $answer]);
        }

        $used = $this->attemptsUsed($context) + 1;
        $allowed = max(1, (int) $context->setting('attempts', 2));

        if ($used < $allowed) {
            $this->rememberAttempts($context, $used);
            $context->runtime->say($this->retry(), $this->choiceLabels($context->step));

            return StepResult::wait()
                ->traced(RunStep::UNKNOWN, ['answer' => $answer, 'attempt' => $used])
                ->spoken();
        }

        $this->rememberAttempts($context, null);

        return StepResult::second()->traced(RunStep::DECLINED, [
            'answer' => $answer,
            'reason' => 'no clear answer within the allowed attempts',
        ]);
    }

    /**
     * True for a yes, false for a no, null when the answer says neither.
     *
     * Null is a third value rather than a default of false so that the caller
     * has to decide what to do with it out loud. Folded into false here, the
     * node would refuse on the first unclear word and never ask again, which is
     * the correct outcome reached rudely; folded into true it would be the
     * failure this whole class is written against.
     *
     * The labels are read before the built-in words, because an author who
     * relabelled the answers meant those labels to be what the visitor types.
     */
    private function verdict(StepContext $context, string $answer): ?bool
    {
        $answer = $this->normalise($answer);

        if ($answer === '') {
            return null;
        }

        $yes = $this->normalise($this->yesLabel($context));
        $no = $this->normalise($this->noLabel($context));

        if ($answer === $yes || $answer === '1') {
            return true;
        }

        if ($answer === $no || $answer === '2') {
            return false;
        }

        if (in_array($answer, $this->yesWords(), true)) {
            return true;
        }

        if (in_array($answer, $this->noWords(), true)) {
            return false;
        }

        if ($no !== '' && str_starts_with($no, $answer)) {
            return false;
        }

        if ($yes !== '' && str_starts_with($yes, $answer)) {
            return true;
        }

        return null;
    }

    private function options(StepContext $context): string
    {
        return '1. '.$this->yesLabel($context).PHP_EOL.'2. '.$this->noLabel($context);
    }

    private function yesLabel(StepContext $context): string
    {
        return $this->labelOf($context->step, 'yes_label', 'yes');
    }

    private function noLabel(StepContext $context): string
    {
        return $this->labelOf($context->step, 'no_label', 'no');
    }

    /**
     * Подпись ответа, одинаково для разговора и для кнопок.
     *
     * Читается из шага, а не из хода разговора: варианты движок спрашивает
     * у узла ещё до того, как ход начался, и вторая копия этого чтения
     * разошлась бы с первой ровно в тот день, когда автор переименует «Да».
     */
    private function labelOf(Step $step, string $setting, string $word): string
    {
        $fallback = (string) __('chatbot::nodes.steps.approval.'.$word);
        $label = trim((string) $step->setting($setting, $fallback));

        return $label === '' ? $fallback : $label;
    }

    /**
     * Counts the unclear answers against this node, on the conversation.
     *
     * Kept with the conversation rather than in the answers bag: it is
     * bookkeeping about the exchange, not something the visitor said, and a
     * counter offered to message templates and to the variable picker is a
     * counter somebody will eventually branch on.
     */
    private function rememberAttempts(StepContext $context, ?int $used): void
    {
        $meta = (array) $context->conversation->meta;
        $name = 'approval:'.$context->step->key;

        if ($used === null) {
            unset($meta[$name]);
        } else {
            $meta[$name] = $used;
        }

        $context->conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }

    private function attemptsUsed(StepContext $context): int
    {
        return (int) (((array) $context->conversation->meta)['approval:'.$context->step->key] ?? 0);
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
