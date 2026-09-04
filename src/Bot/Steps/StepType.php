<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Steps;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * One kind of node in a scenario.
 *
 * Everything the package knows about a node lives here: what it is called, how
 * many outputs it has, what settings it offers, and what it does when the
 * conversation reaches it. Holding all of that in one class is the point — when
 * the answer was split between an enum, a switch in the engine and a panel in
 * the editor's JavaScript, adding a node meant editing three places that could
 * disagree, and the arrow drawn on the canvas could stop matching where the bot
 * actually went.
 *
 * A host adds a node by extending this class and registering it:
 *
 *     Chatbot::registerStep(PickBookingStep::class);
 *
 * Only key(), label() and run() are required. Everything else has a default
 * that describes an ordinary one-output node, so a step that just does
 * something and moves on is about twenty lines.
 *
 * Instances are resolved from the container and are stateless: the same object
 * runs every node of its kind in every conversation. Anything a particular node
 * needs comes from the step row, and anything a particular conversation needs
 * comes from the context.
 */
abstract class StepType
{
    /**
     * The value stored in the step row's type column.
     *
     * Stable forever once a scenario has used it: this string is what a saved
     * graph refers to, and renaming it orphans every node of that kind in every
     * published version. Prefix host keys to keep out of the package's way —
     * "crm.pick_booking" rather than "booking".
     */
    abstract public static function key(): string;

    /** The name shown on the palette and on the node. */
    abstract public function label(): string;

    /**
     * Runs the node and reports what happened.
     *
     * Must not move the conversation itself. Say what was done and which way it
     * went; the engine writes the journal, resolves the target and saves the
     * conversation. A step that saved its own step key would be one crash away
     * from parking a visitor on a node that never asked them anything.
     */
    abstract public function run(StepContext $context): StepResult;

    /**
     * Runs the node again once the visitor has answered it.
     *
     * A waiting node is entered twice and the two entries are nothing alike:
     * the first asks, the second reads a reply. Folding them into run() with a
     * flag was the alternative, and it goes wrong in one specific way — a node
     * that asked, then was re-entered by a resumed delay or a re-sent webhook,
     * cannot tell "they answered" from "we are here again" and asks the same
     * question a second time.
     *
     * The default is for nodes that never wait: nothing to read, carry on. A
     * node that returns StepResult::wait() must override this.
     */
    public function resume(StepContext $context, string $answer): StepResult
    {
        return StepResult::next();
    }

    /** A Heroicon name, drawn on the node and in the palette. */
    public function icon(): string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    /** The node's colour on the canvas, as a hex string. */
    public function color(): string
    {
        return '#64748b';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
    }

    /**
     * The two output labels of a Pair node.
     *
     * Fixed by the node, never editable, and that is the difference between a
     * pair and a branch: a branch option is written and named by the author,
     * while these two mean a result the engine already knows about. Let them be
     * edited and a node appears with four branches the engine cannot route.
     *
     * @return array{0: string, 1: string}
     */
    public function pairLabels(): array
    {
        return ['ok', 'failed'];
    }

    /**
     * Whether the conversation stops here until the visitor replies.
     *
     * A node that asks something conditionally — a form that only asks for what
     * it does not already know — must answer false and return
     * StepResult::wait() when it does ask. The engine cannot tell from the kind
     * of node alone, only from what happened this time.
     */
    public function waitsForReply(): bool
    {
        return false;
    }

    /**
     * Whether the node's own body text is sent to the visitor before it runs.
     *
     * False for nodes whose message comes from somewhere else — a knowledge
     * article, a model's reply. Say true for those and the editor offers a text
     * box that goes nowhere, while the bot sends an empty line ahead of the real
     * answer.
     */
    public function speaks(): bool
    {
        return false;
    }

    /** Whether reaching this node ends the branch on its own. */
    public function ends(): bool
    {
        return false;
    }

    /**
     * Whether the node belongs in the main palette rather than under "more".
     *
     * A row of twenty-five buttons reads as a settings list, not a choice, and
     * the seven nodes every scenario is built from get lost in it.
     */
    public function isBasic(): bool
    {
        return false;
    }

    /**
     * Which node kinds are usually placed after this one.
     *
     * Knowledge about scenarios, not about the screen, which is why it lives
     * with the node. An empty canvas asks "where do I start"; a canvas with a
     * node selected asks "what next", and answering the second question with the
     * full list of kinds is not answering it.
     *
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return [];
    }

    /**
     * The node's settings, drawn by the editor's generic panel.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Problems with how this node is configured, found while editing.
     *
     * Runs against the draft, not against a live conversation, so it is free to
     * be strict: an unbounded loop, a write with no confirmation above it, a
     * jump to a scenario that does not exist. Anything returned here is drawn on
     * the node and can block publication — which is the only moment these can
     * still be fixed cheaply.
     *
     * @return list<array{severity: string, message: string}>
     */
    public function issues(Step $step, Scenario $scenario): array
    {
        return [];
    }

    /**
     * The external things this node depends on, frozen into a published version.
     *
     * Without them a released scenario changes behaviour silently when someone
     * edits a data resource or a host action it uses. With them the difference
     * is visible, and the release can say what moved underneath it.
     *
     * @return array<string, list<string>>
     */
    public function pins(Step $step): array
    {
        return [];
    }

    /**
     * The description the editor receives.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => static::key(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'color' => $this->color(),
            'outputs' => $this->outputs()->value,
            'pair_labels' => $this->outputs() === StepOutputs::Pair ? $this->pairLabels() : null,
            'waits' => $this->waitsForReply(),
            'speaks' => $this->speaks(),
            'ends' => $this->ends(),
            'basic' => $this->isBasic(),
            'next_types' => $this->nextTypes(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $this->fields()),
        ];
    }

    /**
     * The defaults a freshly dropped node starts with.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            if ($field->defaultValue() !== null) {
                $defaults[$field->name()] = $field->defaultValue();
            }
        }

        return $defaults;
    }
}
