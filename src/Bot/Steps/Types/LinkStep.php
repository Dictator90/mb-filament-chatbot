<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps\Types;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Data\RecordLocator;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Points the conversation at a record of the host application.
 *
 * The missing half of the data nodes. A scenario could already find a record and
 * even create one — the picker remembers a key, the write returns one — and then
 * the key sat in a variable and died with the run. The thread the operator opens
 * afterwards still said nothing about which customer or which order it was, and
 * the only way to attach one was by hand, from memory, off a number written in a
 * message three screens up.
 *
 * What it writes is a morph pair the package never dereferences: a type and an
 * id on the conversation, handed back to the host through `contact` and
 * `subject`. Which of the two is the node's own setting, because they answer
 * different questions — who this is, and what this is about — and a scenario
 * routinely fills them from different resources in the same conversation.
 *
 * **The type is never typed in.** It comes from a declared data resource, and
 * that is the whole security of the node: `contact_type` is a model class, and a
 * free-text field there would let whoever writes scenarios aim a conversation at
 * any model in the application — including one no data resource ever exposed —
 * after which the host, doing exactly what it was told, renders that record to
 * whoever is in the chat. Going through a resource also means the key is checked
 * against that resource's access scopes, so a visitor who names somebody else's
 * order number links to nothing rather than to somebody else's order.
 */
final class LinkStep extends StepType
{
    /**
     * Which pair of columns each role writes.
     *
     * A closed map for the same reason `ConversationFacts` keeps one: these are
     * the two morphs the package promises to store and return, and a node that
     * could name any column would make the conversation row a scratch pad the
     * next release cannot change.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ROLES = [
        'contact' => ['contact_type', 'contact_id'],
        'subject' => ['subject_type', 'subject_id'],
    ];

    public function __construct(private readonly RecordLocator $locator) {}

    public static function key(): string
    {
        return 'link';
    }

    public function label(): string
    {
        return __('chatbot::nodes.steps.link.label');
    }

    public function icon(): string
    {
        return 'heroicon-o-link';
    }

    public function color(): string
    {
        return '#0d9488';
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
        return array_values((array) __('chatbot::nodes.steps.link.exits'));
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'condition', 'handoff', 'close'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::dataResource('resource')
                ->label(__('chatbot::nodes.steps.link.fields.resource.label'))
                ->required()
                ->hint(__('chatbot::nodes.steps.link.fields.resource.hint')),

            Field::select('role')
                ->label(__('chatbot::nodes.steps.link.fields.role.label'))
                ->required()
                ->default('contact')
                ->options((array) __('chatbot::nodes.steps.link.fields.role.options')),

            Field::variable('key')
                ->label(__('chatbot::nodes.steps.link.fields.key.label'))
                ->required()
                ->hint(__('chatbot::nodes.steps.link.fields.key.hint')),

            Field::toggle('replace')
                ->label(__('chatbot::nodes.steps.link.fields.replace.label'))
                ->default(false)
                ->hint(__('chatbot::nodes.steps.link.fields.replace.hint')),
        ];
    }

    /**
     * Links, or leaves by the second exit saying what stopped it.
     *
     * **Already pointing at this very record is a success.** The scenario asked
     * for a state and the state holds; taking the "could not" exit for it would
     * send a returning visitor down the apology branch on a conversation that is
     * correctly linked.
     *
     * **Already pointing at a different one is not**, unless the author switched
     * replacing on. What is there came from the channel, from the host, or from
     * an earlier and better-informed turn, and a later turn quietly repointing it
     * is how a thread ends up filed under the wrong customer with nothing on the
     * screen to say it moved.
     */
    public function run(StepContext $context): StepResult
    {
        $resource = trim((string) $context->setting('resource', ''));
        $role = (string) $context->setting('role', 'contact');
        $columns = self::ROLES[$role] ?? null;
        $variable = trim((string) $context->setting('key', ''));

        if ($resource === '' || $columns === null || $variable === '') {
            return StepResult::second()->traced(RunStep::FAILED, [
                'reason' => 'The node does not say which resource, which role or which record to link.',
            ]);
        }

        $key = trim((string) $context->runtime->value($variable));

        if ($key === '') {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'resource' => $resource,
                'reason' => "The variable [{$variable}] holds nothing to link to.",
            ]);
        }

        $conversation = $context->conversation;
        $located = $this->locator->locate(
            $resource,
            $key,
            RuntimeContext::forConversation($conversation),
        );

        if (! $located->ok()) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, [
                'resource' => $resource,
                'reason' => $located->error(),
            ]);
        }

        $record = (array) $located->first();

        return $this->attach($context, $conversation, $columns, $resource, $role, $record);
    }

    /**
     * @param  array{0: string, 1: string}  $columns
     * @param  array<string, scalar|null>  $record
     */
    private function attach(
        StepContext $context,
        Conversation $conversation,
        array $columns,
        string $resource,
        string $role,
        array $record,
    ): StepResult {
        [$typeColumn, $idColumn] = $columns;

        $type = (string) ($record['type'] ?? '');
        $id = $record['id'] ?? null;

        $sameRecord = (string) $conversation->{$typeColumn} === $type
            && (string) $conversation->{$idColumn} === (string) $id;

        if (! $sameRecord && filled($conversation->{$typeColumn}) && ! $context->setting('replace', false)) {
            return StepResult::second()->traced(RunStep::FAILED, [
                'resource' => $resource,
                'role' => $role,
                'reason' => 'The conversation is already linked to another record and this node may not replace it.',
            ]);
        }

        if (! $sameRecord) {
            $conversation->forceFill([$typeColumn => $type, $idColumn => $id])->save();
        }

        return StepResult::next()->traced(RunStep::PASSED, [
            'resource' => $resource,
            'role' => $role,
            'record' => $id,
            'already_linked' => $sameRecord,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function pins(Step $step): array
    {
        $resource = trim((string) $step->setting('resource', ''));

        return $resource === '' ? [] : ['data_resources' => [$resource]];
    }

    /**
     * Nothing here writes a variable — the link is on the conversation, and the
     * key it was made from is already in the variable this node read.
     *
     * @return list<string>
     */
    public function writes(Step $step): array
    {
        return [];
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        $issues = [];

        if (trim((string) $step->setting('resource', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.link.issues.no_resource')];
        }

        if (($missing = $this->resourceIssue($step)) !== null) {
            $issues[] = $missing;
        }

        if (trim((string) $step->setting('key', '')) === '') {
            $issues[] = ['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.link.issues.no_key')];
        }

        /*
         * A role outside the map is caught while editing rather than at
         * runtime. Left alone it is a node that refuses every conversation it
         * ever runs in, and the only place that says so is a trace nobody reads
         * until somebody complains the bot went quiet.
         */
        if (! isset(self::ROLES[(string) $step->setting('role', 'contact')])) {
            $issues[] = ['severity' => 'blocking', 'message' => __('chatbot::nodes.steps.link.issues.bad_role')];
        }

        return $issues;
    }
}
