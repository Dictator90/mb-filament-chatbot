<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Slots\SlotKind;
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
    /**
     * The attempts map's value for a slot the form has stopped asking about.
     *
     * Negative so that it can never collide with a count of attempts, which
     * starts at one.
     */
    private const ABANDONED = -1;

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

    /**
     * One name per slot the form asks for, under the slot's own key.
     *
     * The node with the widest gap between what it does and what it says it
     * does. An author picks four slots from a multiple select and gets four
     * variables they were never shown a name for — and the message on the next
     * node, where those names have to be typed by hand, is the first place the
     * mistake shows, as a missing word in a sentence a customer is reading.
     *
     * @return list<string>
     */
    public function writes(Step $step): array
    {
        return array_column(self::slots($step->setting('slots', [])), 'key');
    }

    /**
     * The slots this form asks for, in either shape a stored graph can hold.
     *
     * The select on the settings panel writes plain keys, and for a graph drawn
     * on the canvas that is all this ever sees. A graph that arrives any other
     * way — a host's seeder, an import, a scenario written in PHP — may hold
     * `{"slot": "phone", "required": true}` instead, which is the richer thing
     * an author means and has no way to draw.
     *
     * Reading only the first shape did not ignore the second: it cast an array
     * to a string, and the fatal landed in the middle of the bot's turn. On an
     * installation whose updates are polled it took the poller down with it, so
     * one scenario's settings stopped every bot on the installation — and the
     * conversation on screen simply ended mid-question.
     *
     * @return list<array{key: string, required: bool}>
     */
    private static function slots(mixed $setting): array
    {
        $slots = [];

        foreach ((array) $setting as $entry) {
            if (is_array($entry)) {
                $key = trim((string) ($entry['slot'] ?? $entry['key'] ?? ''));
                $required = (bool) ($entry['required'] ?? true);
            } elseif (is_scalar($entry)) {
                $key = trim((string) $entry);
                $required = true;
            } else {
                continue;
            }

            if ($key !== '') {
                $slots[] = ['key' => $key, 'required' => $required];
            }
        }

        return $slots;
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
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()), $this->choicesOf($slot));

            return StepResult::wait()->traced(RunStep::WAITING, [
                'slot' => $slot->key(),
                'attempt' => $used,
            ]);
        }

        $this->rememberAttempts($context, $slot->key(), null);

        /*
         * An optional slot that nobody could answer is not a failed form. The
         * author marked it optional precisely because the conversation is worth
         * having without it — a budget nobody has decided yet is the case this
         * was written for — and taking the "could not" exit would hand the
         * visitor to a person over the one answer that did not matter.
         */
        if (! $this->isRequired($context, $slot->key())) {
            $this->abandon($context, $slot->key());

            return $this->askNextOrFinish($context, [$slot->key()]);
        }

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
        foreach (self::slots($context->setting('slots', [])) as $wanted) {
            $key = $wanted['key'];

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

            if ($this->abandoned($context, $key)) {
                continue;
            }

            $context->conversation->forceFill(['bot_slot' => $slot->key()])->save();
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()), $this->choicesOf($slot));

            return StepResult::wait()->traced(RunStep::WAITING, ['slot' => $slot->key()]);
        }

        $this->stopAsking($context);

        return StepResult::next()->traced(RunStep::ANSWERED, ['collected' => true]);
    }

    /**
     * The answers a choice slot already knows, offered as buttons.
     *
     * Without them a question like "beach, sightseeing or both?" arrived as
     * bare text: the visitor had to type one of the words exactly, and a
     * channel that draws buttons for every other question drew none here. The
     * parser already accepts the position as well as the label, so a pressed
     * button and a typed word land on the same value.
     *
     * @return list<string>
     */
    private function choicesOf(Slot $slot): array
    {
        return $slot->kind() === SlotKind::Choice
            ? array_values(array_map(static fn (mixed $label): string => (string) $label, $slot->choices()))
            : [];
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

    private function isRequired(StepContext $context, string $slotKey): bool
    {
        foreach (self::slots($context->setting('slots', [])) as $wanted) {
            if ($wanted['key'] === $slotKey) {
                return $wanted['required'];
            }
        }

        return true;
    }

    /**
     * Marks an optional slot as given up on, for the rest of this form.
     *
     * Without it the next answer walks the list again, finds the same empty
     * optional slot and asks for it a second time — the attempts counter having
     * just been cleared — which is the loop a visitor leaves rather than sits
     * through.
     *
     * It shares the attempts map instead of a column of its own: the map is
     * already keyed by step and slot, already cleared when the form ends, and a
     * column would be a migration for a flag that never outlives one node.
     */
    private function abandon(StepContext $context, string $slotKey): void
    {
        $this->rememberAttempts($context, $slotKey, self::ABANDONED);
    }

    private function abandoned(StepContext $context, string $slotKey): bool
    {
        return $this->attemptsUsed($context, $slotKey) === self::ABANDONED;
    }

    /**
     * Ends the form and forgets what it counted along the way.
     *
     * Per step rather than wholesale: a scenario may hold two forms, and the
     * conversation can be in the second while the first is still remembered by
     * a sub-scenario that has not returned yet.
     */
    private function stopAsking(StepContext $context): void
    {
        $prefix = $context->step->key.':';

        $attempts = array_filter(
            (array) $context->conversation->bot_slot_attempts,
            fn (string $name): bool => ! str_starts_with($name, $prefix),
            ARRAY_FILTER_USE_KEY,
        );

        $context->conversation->forceFill([
            'bot_slot' => null,
            'bot_slot_attempts' => $attempts === [] ? null : $attempts,
        ])->save();
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
