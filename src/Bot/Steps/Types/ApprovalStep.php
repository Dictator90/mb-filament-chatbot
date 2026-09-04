<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

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
    /** The wording used when the answer was neither a yes nor a no. */
    private const RETRY = 'Sorry, I did not catch that. Please answer with one of these:';

    private const YES_WORDS = ['yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'confirm', 'confirmed', 'agree'];

    private const NO_WORDS = ['no', 'nope', 'nah', 'cancel', 'stop', 'decline', 'abort'];

    public static function key(): string
    {
        return 'approval';
    }

    public function label(): string
    {
        return 'Ask for confirmation';
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
        return ['approved', 'declined'];
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
                ->label('The confirming answer')
                ->default('Yes'),

            Field::text('no_label')
                ->label('The refusing answer')
                ->default('No'),

            Field::number('attempts')
                ->label('Attempts')
                ->default(2)
                ->min(1)
                ->max(5)
                ->hint('After the last one an answer that is neither counts as a refusal.'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $this->rememberAttempts($context, null);
        $context->runtime->say($this->options($context));

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
            $context->runtime->say(self::RETRY.PHP_EOL.$this->options($context));

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

        if (in_array($answer, self::YES_WORDS, true)) {
            return true;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
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
        $label = trim((string) $context->setting('yes_label', 'Yes'));

        return $label === '' ? 'Yes' : $label;
    }

    private function noLabel(StepContext $context): string
    {
        $label = trim((string) $context->setting('no_label', 'No'));

        return $label === '' ? 'No' : $label;
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
