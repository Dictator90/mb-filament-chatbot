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
use MB\FilamentChatbot\Database\Factories\ScenarioFactory;

/**
 * One self-contained conversation the bot knows how to have.
 *
 * A scenario holds only what applies to the whole of it — its name, its slug,
 * whether it is switched on and in which order it is tried. What starts it
 * lives in the start node instead: while the trigger was a field here, the
 * diagram began somewhere above itself, and looking at the graph you could not
 * tell what a visitor has to say for the bot to speak at all.
 *
 * @property int $id
 * @property int|null $bot_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property int $priority
 * @property string|null $description
 * @property array<string, mixed>|null $invocation
 * @property int|null $live_version_id
 * @property Collection<int, Step> $steps
 */
class Scenario extends Model
{
    /** @use HasFactory<ScenarioFactory> */
    use HasFactory;

    protected $table = 'chatbot_scenarios';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'invocation' => 'array',
            'graph_notes' => 'array',
            'graph_groups' => 'array',
            'viewport' => 'array',
        ];
    }

    /**
     * Finds a step by its key.
     *
     * Reads the loaded collection when there is one and only falls back to a
     * query otherwise. Walking a scenario calls this on every single step —
     * once to find where to start, once per transition — so a query per call
     * would turn one conversation turn into a dozen round trips, growing with
     * the length of the scenario rather than staying flat.
     *
     * The fallback is a targeted query and not loadMissing(): a caller that
     * arrives here with one key in hand wants one row, and pulling every step
     * of the scenario into memory to answer it would trade N+1 for a different
     * kind of waste.
     */
    public function step(?string $key): ?Step
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($this->relationLoaded('steps')) {
            return $this->steps->firstWhere('key', $key);
        }

        return $this->steps()->where('key', $key)->first();
    }

    /**
     * The start node. A scenario that has been created but not yet drawn has
     * none, and that is not a failure: the runtime simply skips it.
     */
    public function startStep(): ?Step
    {
        if ($this->relationLoaded('steps')) {
            return $this->steps->firstWhere('type', Step::TYPE_START);
        }

        return $this->steps()->where('type', Step::TYPE_START)->first();
    }

    /**
     * Whether the scenario has ever been published.
     *
     * Answered from the column rather than the relation: the scenario list
     * shows this per row, and asking the relation would be one query per line
     * on screen.
     *
     * An unpublished scenario is still executed, which is a decision and not
     * an oversight — forbid the draft and a fresh installation would meet its
     * first customer in silence, because seeded scenarios have never been
     * through a publish. But it must not be implicit: while a scenario runs as
     * a draft, every edit reaches customers the moment it is saved, which is
     * exactly what versions were introduced to stop.
     */
    public function isPublished(): bool
    {
        return $this->live_version_id !== null;
    }

    /**
     * @param  Builder<Scenario>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scenarios a given bot may run.
     *
     * Includes the ones with no bot at all, because a null bot_id means
     * "available to every bot". Filtering on equality alone would hide exactly
     * the shared scenarios — the fallbacks and the handover — from every bot
     * that has them.
     *
     * @param  Builder<Scenario>  $query
     */
    public function scopeForBot(Builder $query, Bot|int|null $bot): void
    {
        $botId = $bot instanceof Bot ? $bot->getKey() : $bot;

        $query->where(function (Builder $query) use ($botId): void {
            $query->whereNull('bot_id');

            if ($botId !== null) {
                $query->orWhere('bot_id', $botId);
            }
        });
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    /**
     * Ordered by sort and then id, so that two steps sharing a sort value do
     * not swap places between requests. An unstable order here would make the
     * canvas redraw differently every time it is opened.
     *
     * @return HasMany<Step, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(Step::class, 'scenario_id')
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * Published snapshots, newest first. The order lives here rather than on
     * each screen: the version history is read in order to find somewhere to
     * roll back to, and that search always starts from the most recent.
     *
     * @return HasMany<ScenarioVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ScenarioVersion::class, 'scenario_id')->latest('number');
    }

    /**
     * The snapshot answering customers right now. Empty for a scenario that
     * has never been published: it is being edited, but nothing of it is live.
     *
     * @return BelongsTo<ScenarioVersion, $this>
     */
    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'live_version_id');
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'scenario_id');
    }

    /**
     * Factories live in the package namespace, which Laravel's default guess
     * (Database\Factories\ beside the application) never finds.
     */
    protected static function newFactory(): Factory
    {
        return ScenarioFactory::new();
    }
}
