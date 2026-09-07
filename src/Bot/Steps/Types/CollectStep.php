<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Slots\SlotParser;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Chat\ConversationFacts;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Facades\Chatbot;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Asks for exactly one thing and remembers the answer.
 *
 * Always asks, even when the answer is already known — which is the whole
 * difference between this node and the form. Use it where the question is the
 * point ("what is the new address?"), and the form where the answers are.
 *
 * It has one output and no way to fail, and that is deliberate. After the
 * allowed number of attempts it stores what the visitor literally wrote and
 * carries on: a person who has typed their phone number twice and been told
 * twice that it is not a phone number will not type it a third time, and an
 * operator reading "call me on the shop line" understands it perfectly. A
 * scenario that genuinely cannot continue without a parsed value wants the form
 * node, which has a branch for giving up.
 */
final class CollectStep extends StepType
{
    public function __construct(private readonly SlotParser $parser) {}

    public static function key(): string
    {
        return 'collect';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.collect.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-identification';
    }

    public function color(): string
    {
        return '#0ea5e9';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
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
        return ['condition', 'say', 'form', 'close'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('slot')
                ->label(__('chatbot::nodes.steps.collect.fields.slot.label'))
                ->required()
                ->options(fn (): array => collect(Chatbot::slots())
                    ->mapWithKeys(fn (Slot $slot): array => [$slot->key() => $slot->name()])
                    ->all()),
            Field::text('into')
                ->label(__('chatbot::nodes.steps.collect.fields.into.label'))
                ->hint(__('chatbot::nodes.steps.collect.fields.into.hint')),
            Field::number('attempts')
                ->label(__('chatbot::nodes.steps.collect.fields.attempts.label'))
                ->default(2)
                ->min(1)
                ->max(5),
            /*
             * Offered rather than inferred from the slot's name.
             *
             * A slot called `name` is the obvious word for a dozen unrelated
             * questions — a pet's name, a company's, the name on a card — and a
             * package that renamed the conversation whenever it saw one would be
             * guessing about the field an operator reads first. Asked for
             * explicitly, it is a decision somebody made and can see on the node.
             */
            Field::select('remember_as')
                ->label(__('chatbot::nodes.steps.collect.fields.remember_as.label'))
                ->hint(__('chatbot::nodes.steps.collect.fields.remember_as.hint'))
                ->default('')
                ->options(fn (): array => ['' => __('chatbot::nodes.steps.collect.fields.remember_as.none')]
                    + self::conversationFields()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function conversationFields(): array
    {
        $options = [];

        foreach (array_keys(ConversationFacts::FIELDS) as $field) {
            $options[$field] = __("chatbot::nodes.conversation_fields.{$field}");
        }

        return $options;
    }

    /**
     * Puts the answer on the conversation as well, when the node says to.
     *
     * Returns what it wrote so the run journal says so. An operator looking at a
     * thread that renamed itself mid-conversation should be able to find the
     * node that did it, and "the name changed and nothing recorded why" is the
     * kind of thing that gets blamed on the bot in general.
     */
    private function rememberOnConversation(StepContext $context, string $value): ?string
    {
        $choice = trim((string) $context->setting('remember_as', ''));

        if ($choice === '') {
            return null;
        }

        return app(ConversationFacts::class)->remember($context->conversation, $choice, $value)
            ? $choice
            : null;
    }

    public function run(StepContext $context): StepResult
    {
        $slot = $this->slot($context);

        if ($slot === null) {
            return StepResult::next()->traced(RunStep::FAILED, ['reason' => 'no slot chosen']);
        }

        /*
         * The engine has already sent the node's own text when there is any. A
         * node left without text still has to ask something, and the slot's
         * default question is what it was written for.
         */
        if (trim((string) $context->step->body) === '') {
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()));
        }

        $this->rememberAsking($context, 0);

        return StepResult::wait()->traced(RunStep::WAITING, ['slot' => $slot->key()]);
    }

    public function resume(StepContext $context, string $answer): StepResult
    {
        $slot = $this->slot($context);

        if ($slot === null) {
            return StepResult::next()->traced(RunStep::FAILED, ['reason' => 'no slot chosen']);
        }

        $name = $this->storeAs($context, $slot);
        $parsed = $this->parser->parse($slot, $answer);

        if ($parsed->ok()) {
            $context->runtime->store($name, $parsed->value());

            foreach ($parsed->extra() as $key => $value) {
                $context->runtime->store($name.'_'.$key, (string) $value);
            }

            $this->rememberAsking($context, null);
            $remembered = $this->rememberOnConversation($context, $parsed->value());

            return StepResult::next()->traced(RunStep::ANSWERED, array_filter([
                'slot' => $slot->key(),
                'variable' => $name,
                'value' => $parsed->value(),
                'remembered_as' => $remembered,
            ], static fn (mixed $value): bool => $value !== null));
        }

        $used = $this->attemptsUsed($context) + 1;
        $allowed = max(1, (int) $context->setting('attempts', 2));

        if ($used < $allowed) {
            $this->rememberAsking($context, $used);
            $context->runtime->say($context->runtime->fill($slot->defaultQuestion()));

            return StepResult::wait()->traced(RunStep::WAITING, [
                'slot' => $slot->key(),
                'attempt' => $used,
            ]);
        }

        $context->runtime->store($name, $parsed->raw());
        $this->rememberAsking($context, null);

        return StepResult::next()->traced(RunStep::ANSWERED, [
            'slot' => $slot->key(),
            'variable' => $name,
            'value' => $parsed->raw(),
            'parsed' => false,
        ]);
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        if (trim((string) $step->setting('slot', '')) === '') {
            return [['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.collect.issues.no_slot')]];
        }

        return [];
    }

    private function slot(StepContext $context): ?Slot
    {
        return Chatbot::slot((string) $context->setting('slot', ''));
    }

    private function storeAs(StepContext $context, Slot $slot): string
    {
        $into = trim((string) $context->setting('into', ''));

        return $into === '' ? $slot->key() : $into;
    }

    /**
     * Counts attempts against the node, not against the slot.
     *
     * The same slot can be asked for in two places of one scenario — a phone
     * number at the start and again after a correction — and counting by slot
     * would carry the earlier failures into the later question, giving the
     * visitor one attempt where the author wrote two.
     */
    private function rememberAsking(StepContext $context, ?int $used): void
    {
        $attempts = (array) $context->conversation->bot_slot_attempts;

        if ($used === null) {
            unset($attempts[$context->step->key]);
        } else {
            $attempts[$context->step->key] = $used;
        }

        $context->conversation->forceFill([
            'bot_slot_attempts' => $attempts === [] ? null : $attempts,
        ])->save();
    }

    private function attemptsUsed(StepContext $context): int
    {
        $attempts = (array) $context->conversation->bot_slot_attempts;

        return (int) ($attempts[$context->step->key] ?? 0);
    }
}
