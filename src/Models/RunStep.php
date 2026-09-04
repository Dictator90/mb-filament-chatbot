<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MB\FilamentChatbot\Database\Factories\RunStepFactory;

/**
 * One node a conversation genuinely passed through.
 *
 * A transcript shows what the bot said; only the trace shows which branch it
 * took to say it, and those two answers diverge exactly where the scenario is
 * wrong.
 *
 * Rows are appended and never updated, so there are no timestamps here — there
 * is occurred_at, and a created_at beside it would be a second answer to the
 * same question that can drift from the first when a delayed step is written
 * from a queue.
 *
 * **Outcomes are constants, not an enum.** There are two dozen of them and
 * nearly every one means "this branch of the engine fired", so an enum would
 * amount to a second dictionary of step kinds differing from the first in ways
 * nobody could explain. More importantly, an unfamiliar value has to stay
 * readable: a host registers its own steps with their own outcomes, and a
 * strict cast would make one old row stop the whole journal from opening.
 *
 * @property int $id
 * @property int $run_id
 * @property int $seq
 * @property string|null $step_key
 * @property string|null $step_type
 * @property string|null $scenario_slug
 * @property string|null $said
 * @property string $outcome
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
class RunStep extends Model
{
    /** @use HasFactory<RunStepFactory> */
    use HasFactory;

    /** Said its piece and moved on through its only exit. */
    public const string PASSED = 'passed';

    /** Sent a reply to the visitor. */
    public const string SAID = 'said';

    /** Asked, and stopped the walk until an answer arrives. */
    public const string WAITING = 'waiting';

    /** A split by words or by condition chose a branch. */
    public const string BRANCHED = 'branched';

    /** The conversation moved to another scenario; the run continued here. */
    public const string JUMPED = 'jumped';

    /** Entered a sub-scenario, expecting to come back. */
    public const string CALLED = 'called';

    /** Came back from a sub-scenario to the step after the call. */
    public const string RETURNED = 'returned';

    /** A pause step: continuation was scheduled as a delayed job. */
    public const string DELAYED = 'delayed';

    /** A human was called in. */
    public const string HANDED_OFF = 'handed_off';

    /** The conversation was closed. */
    public const string CLOSED = 'closed';

    /** The visitor's answer matched an option and the walk went on. */
    public const string ANSWERED = 'answered';

    /**
     * Nothing matched the answer, and the bot asked again.
     *
     * The most common reason people give up on a bot, and the one thing a
     * transcript cannot be read to reveal: "did not understand" and "the
     * visitor never replied" look identical in the messages.
     */
    public const string UNKNOWN = 'unknown';

    /** A data resource was queried on the host's records. */
    public const string QUERIED = 'queried';

    /** The query ran and matched nothing — the second exit of a lookup. */
    public const string EMPTY_RESULT = 'empty_result';

    /** An approval came back yes. */
    public const string APPROVED = 'approved';

    /** An approval came back no. */
    public const string DECLINED = 'declined';

    /**
     * The step itself failed: an outbound call refused, a model unavailable,
     * a host action throwing. Distinct from EMPTY_RESULT and DECLINED, which
     * are answers — this one means there was no answer at all, and it is
     * fixed by settings or by the other system rather than by the scenario.
     */
    public const string FAILED = 'failed';

    protected $table = 'chatbot_run_steps';

    protected $guarded = [];

    /**
     * The table carries occurred_at instead of the usual pair, so Eloquent
     * must be told not to write columns that are not there.
     */
    public $timestamps = false;

    /**
     * step_type is not cast to anything.
     *
     * A node may be of a kind the host has since removed from its registry,
     * and a strict cast would throw on read and take the entire journal down
     * for the sake of one historical row — precisely when that history is what
     * somebody came to look at.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    protected static function newFactory(): Factory
    {
        return RunStepFactory::new();
    }
}
