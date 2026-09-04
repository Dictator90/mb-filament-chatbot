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
 * Where a scenario begins, and on what.
 *
 * What launches a scenario is a node rather than a page of scenario settings,
 * and that is the whole point of the class. As a setting it lived off the
 * canvas: the drawing began with an arrow coming from nowhere, and no one
 * reading it could say which words made the bot speak at all, or whether it
 * answered strangers as well as customers — they had to open a form somewhere
 * else and hold both in their head. Worse, a scenario has one beginning and two
 * places were describing it, the settings page and the node the editor drew to
 * anchor the arrow; the first edit that touched only one of them left a graph
 * that said one thing and a bot that did another.
 *
 * The node itself does nothing when the walk reaches it. By then the decision
 * it describes has already been made — the engine consulted these settings to
 * pick this scenario in the first place — so it passes straight through to its
 * only exit.
 */
final class StartStep extends StepType
{
    public static function key(): string
    {
        return Step::TYPE_START;
    }

    public function label(): string
    {
        return 'Start';
    }

    public function icon(): string
    {
        return 'heroicon-o-play';
    }

    public function color(): string
    {
        return '#16a34a';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
    }

    public function isBasic(): bool
    {
        return true;
    }

    /**
     * The start node never sends its own text.
     *
     * A greeting is a message like any other and belongs in a say node after
     * this one, where an author can see it, reword it and route around it.
     * Folded into the start node it would be a message with no node of its own:
     * invisible on the canvas and impossible to skip for a returning visitor.
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
        return ['say', 'ask', 'answer', 'condition'];
    }

    /**
     * The channel list is left empty here on purpose.
     *
     * Which channels exist is a property of the installation, not of the node,
     * and it changes while the panel is running — a Telegram bot is connected
     * and the choice has to appear without a package release. The editor fills
     * these options in from the registered channel drivers.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::select('trigger')
                ->label('Starts on')
                ->options([
                    'greeting' => 'A conversation opening',
                    'keyword' => 'One of these words',
                    'fallback' => 'Anything nothing else answered',
                    'call' => 'Being called from another scenario',
                ])
                ->default('keyword')
                ->required()
                ->hint('Exactly one scenario per bot should be the fallback, or two of them will race for the same message.'),

            Field::list('keywords')
                ->label('Words')
                ->hint('Matched inside the message, on whole words.')
                ->visibleWhen('trigger', 'keyword'),

            Field::select('channels')
                ->label('Only on these channels')
                ->options([])
                ->multiple()
                ->hint('Leave empty for every channel this bot answers on.'),

            Field::select('audience')
                ->label('Only for')
                ->options([
                    'any' => 'Anyone',
                    'known' => 'Visitors we have identified',
                    'anonymous' => 'Visitors we have not identified',
                ])
                ->default('any'),

            Field::select('familiarity')
                ->label('Only when')
                ->options([
                    'any' => 'Every conversation',
                    'new' => 'This is their first conversation',
                    'returning' => 'They have written before',
                ])
                ->default('any'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        return StepResult::next()->traced(RunStep::PASSED);
    }
}
