<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Slots\SlotParser;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Facades\Chatbot;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Collects several things at once, asking only for what is missing.
 *
 * The node that saves scenarios from becoming forms. Five things to find out
 * would otherwise be five collect nodes in a row, and half of them would ask
 * for something already known — a phone number that arrived with the widget's
 * contact form, a name that came from the visitor's account. Being asked again
 * for something you have already given is the single most recognisable thing a
 * bot does wrong.
 *
 * Two outputs. "Collected" means every required slot has a value. "Could not"
 * means the visitor failed the same question the allowed number of times, and
 * that branch exists so the scenario can fetch a person instead of asking a
 * third time.
 *
 * A form may ask nothing at all and pass straight through, which is why the
 * engine cannot know from the node kind alone whether it stops: only this node
 * knows, and only after it has looked.
 */
final class FormStep extends StepType
{
    public function __construct(private readonly SlotParser $parser) {}

    public static function key(): string
    {
        return 'form';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.form.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public function color(): string
    {
        return '#6366f1';
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
        return array_values((array) __('chatbot::nodes.steps.form.exits'));
    }

    public function isBasic(): bool
    {
        return true;
    }

    public function speaks(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'close', 'handoff'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('slots')
                ->label(__('chatbot::nodes.steps.form.fields.slots.label'))
                ->required()
                ->multiple()
                ->options(fn (): array => collect(Chatbot::slots())
                    ->mapWithKeys(fn (Slot $slot): array => [$slot->key() => $slot->name()])
                    ->all()),
            Field::number('attempts')
                ->label(__('chatbot::nodes.steps.form.fields.attempts.label'))
                ->default(2)
                ->min(1)
                ->max(5),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        return $this->askNextOrFinish($context, []);
    }

    public function resume(StepContext $context, string $answer): StepResult
    {
        $asking = (string) $context->conversation->bot_slot;
        $slot = Chatbot::slot($asking);

        if ($slot === null) {
            return $this->askNextOrFinish($context, []);
        }

        $parsed = $this->parser->parse($slot, $answer);

        if ($parsed->ok()) {
            $context->runtime->store($slot->key(), $parsed->value());

            foreach ($parsed->extra() as $key => $value) {
                $context->runtime->store($slot->key().'_'.$key, (string) $value);
            }

            $this->rememberAttempts($context, $slot->key(), null);

            return $this->askNextOrFinish($context, [$slot->key()]);
        }

        $used = $this->attemptsUsed($context, $slot->key()) + 1;
        $allowed = max(1, (int) $context->setting('attempts', 2));

        if ($used < $allowed) {
            $this->rememberAttempts($context, $slot->key(), $used);
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()));

            return StepResult::wait()->traced(RunStep::WAITING, [
                'slot' => $slot->key(),
                'attempt' => $used,
            ]);
        }

        $this->rememberAttempts($context, $slot->key(), null);
        $this->stopAsking($context);

        return StepResult::second()->traced(RunStep::FAILED, [
            'slot' => $slot->key(),
            'reason' => 'no usable answer',
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        if ((array) $step->setting('slots', []) === []) {
            return [['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.form.issues.nothing_collected')]];
        }

        return [];
    }

    /**
     * Asks for the first thing still missing, or finishes.
     *
     * @param  list<string>  $justAnswered
     */
    private function askNextOrFinish(StepContext $context, array $justAnswered): StepResult
    {
        foreach ((array) $context->setting('slots', []) as $key) {
            $key = (string) $key;

            if (in_array($key, $justAnswered, true)) {
                continue;
            }

            $slot = Chatbot::slot($key);

            if ($slot === null) {
                continue;
            }

            if ($this->alreadyKnown($context, $slot)) {
                continue;
            }

            $context->conversation->forceFill(['bot_slot' => $slot->key()])->save();
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()));

            return StepResult::wait()->traced(RunStep::WAITING, ['slot' => $slot->key()]);
        }

        $this->stopAsking($context);

        return StepResult::next()->traced(RunStep::ANSWERED, ['collected' => true]);
    }

    /**
     * A value counts as known only when it is not blank.
     *
     * An empty string in the answers is what an earlier node writes when it
     * looked and found nothing, and treating that as an answer would skip the
     * one question the form was added to ask.
     */
    private function alreadyKnown(StepContext $context, Slot $slot): bool
    {
        return trim((string) $context->runtime->value($slot->key())) !== '';
    }

    private function stopAsking(StepContext $context): void
    {
        $context->conversation->forceFill(['bot_slot' => null])->save();
    }

    private function rememberAttempts(StepContext $context, string $slotKey, ?int $used): void
    {
        $attempts = (array) $context->conversation->bot_slot_attempts;
        $name = $context->step->key.':'.$slotKey;

        if ($used === null) {
            unset($attempts[$name]);
        } else {
            $attempts[$name] = $used;
        }

        $context->conversation->forceFill([
            'bot_slot_attempts' => $attempts === [] ? null : $attempts,
        ])->save();
    }

    private function attemptsUsed(StepContext $context, string $slotKey): int
    {
        $attempts = (array) $context->conversation->bot_slot_attempts;

        return (int) ($attempts[$context->step->key.':'.$slotKey] ?? 0);
    }
}
