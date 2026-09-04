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
 * Waits a moment before the next node runs.
 *
 * Three messages landing in the same second is the first thing that gives a bot
 * away, and it is not a matter of taste: the visitor is still reading the
 * greeting when the question and its buttons push it off the screen, so they
 * answer a question they never read. A pause between them costs nothing and
 * fixes both.
 *
 * The pause is a scheduled continuation, never a sleeping request. Holding the
 * request open for the same three seconds would hold a worker open too, and a
 * hundred conversations pausing politely at once is a queue of workers doing
 * nothing — the bot stops answering anybody. That is why this returns a delay
 * for the engine to schedule rather than sleeping here.
 */
final class DelayStep extends StepType
{
    private const DEFAULT_SECONDS = 2;

    private const MIN_SECONDS = 1;

    private const MAX_SECONDS = 3600;

    public static function key(): string
    {
        return 'delay';
    }

    public function label(): string
    {
        return 'Pause';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Single;
    }

    /**
     * @return list<string>
     */
    public function nextTypes(): array
    {
        return ['say', 'ask'];
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            Field::number('seconds')
                ->label('Seconds')
                ->default(self::DEFAULT_SECONDS)
                ->min(self::MIN_SECONDS)
                ->max(self::MAX_SECONDS)
                ->hint('A second or two reads as typing. Longer pauses read as the bot having stopped.'),
        ];
    }

    /**
     * The bounds are enforced here as well as on the field.
     *
     * The field's min and max guard the editor, and the editor is not the only
     * way a step row is written: seeders, imported scenarios and a host's own
     * migration all reach the same column. A zero coming from any of those
     * throws inside StepResult::delay() and takes the whole turn down, so the
     * value is clamped rather than trusted.
     */
    public function run(StepContext $context): StepResult
    {
        $seconds = (int) $context->setting('seconds', self::DEFAULT_SECONDS);
        $seconds = min(self::MAX_SECONDS, max(self::MIN_SECONDS, $seconds));

        return StepResult::delay($seconds)->traced(RunStep::DELAYED, ['seconds' => $seconds]);
    }
}
