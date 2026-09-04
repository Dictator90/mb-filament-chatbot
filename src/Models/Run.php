<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use MB\FilamentChatbot\Database\Factories\RunFactory;
use MB\FilamentChatbot\Enums\RunOutcome;

/**
 * One pass of the bot through a conversation: from entering a scenario to
 * handing over, closing, or breaking off.
 *
 * A run survives a change of scenario. The "jump to scenario" step does not
 * open a second run, it keeps writing the same trace — every trace step carries
 * its own scenario slug for that reason. Splitting on each jump would cut the
 * one thing the journal exists for, the path a person actually walked, into
 * fragments nobody can reassemble.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $scenario_id
 * @property int|null $version_id
 * @property string|null $scenario_slug
 * @property string|null $channel
 * @property RunOutcome|null $outcome
 * @property int $steps_count
 * @property int $said_count
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Collection<int, RunStep> $steps
 */
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    protected $table = 'chatbot_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => RunOutcome::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'steps_count' => 'integer',
            'said_count' => 'integer',
        ];
    }

    /**
     * Whether the bot is still walking this run.
     *
     * Decided by ended_at rather than by the outcome. "Running" and an empty
     * ended_at are the same state written down twice, and the one to trust is
     * the one an index can check — a run left with a stale outcome after a
     * crash would otherwise be reported as open forever.
     */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Runs that have not finished.
     *
     * @param  Builder<Run>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    /**
     * @return BelongsTo<ScenarioVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'version_id');
    }

    /**
     * The trace, in the order it happened. Ordering belongs here and not on
     * the screens that read it: a trace out of order is not a slightly worse
     * trace, it is a wrong answer about which branch came first.
     *
     * @return HasMany<RunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class, 'run_id')->orderBy('seq');
    }

    protected static function newFactory(): Factory
    {
        return RunFactory::new();
    }
}
