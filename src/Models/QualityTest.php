<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MB\FilamentChatbot\Database\Factories\QualityTestFactory;
use MB\FilamentChatbot\Enums\QualityOutcome;

/**
 * A scripted conversation the bot has to survive.
 *
 * The recorded outcome is the last one only. What a person opening the list needs
 * is whether this is red now; a history of every run would grow forever to answer
 * a question nobody asks, and the transcript of the last failure is what actually
 * gets read when one goes red.
 *
 * @property array<int, mixed>|null $context
 * @property array<int, mixed> $said
 * @property array<int, mixed> $expectations
 * @property QualityOutcome|null $last_outcome
 * @property array<int, mixed>|null $last_mismatches
 * @property array<int, mixed>|null $last_transcript
 * @property array<int, mixed>|null $last_visited
 * @property string|null $last_fingerprint
 * @property bool $is_required
 * @property bool $is_active
 */
class QualityTest extends Model
{
    /** @use HasFactory<QualityTestFactory> */
    use HasFactory;

    protected $table = 'chatbot_quality_tests';

    protected $guarded = [];

    /**
     * last_outcome is cast to the enum; the column stays a plain string.
     *
     * A run writes it, never a person, so there is no keyboard between the enum
     * and the column and no unknown value to survive on read.
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'said' => 'array',
            'expectations' => 'array',
            'last_mismatches' => 'array',
            'last_transcript' => 'array',
            'last_visited' => 'array',
            'last_outcome' => QualityOutcome::class,
            'last_run_at' => 'datetime',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The tests that block a release rather than merely inform it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    /**
     * The scripted lines, as plain strings.
     *
     * The column holds whatever put it there, and a repeater of simple fields
     * writes a flat list of strings while a seeder or a hand-written row may
     * hold anything. This list is what the screen prints under the check's
     * name, so it coerces rather than trusting: a check carrying one stray
     * array must still be readable, not blank.
     *
     * @return list<string>
     */
    public function saidLines(): array
    {
        $lines = [];

        foreach ((array) $this->said as $line) {
            $line = is_scalar($line) ? trim((string) $line) : '';

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A check pointing at a scenario that is no longer there.
     *
     * Deleting a scenario nulls the key and leaves the slug, and the two
     * together are the only way to tell this state from the other one that also
     * has no key: a check of the whole bot, where the picker chooses. Without
     * the distinction an orphan quietly becomes a check of everything — it goes
     * on passing, guarding something nobody asked it to guard.
     */
    public function isOrphaned(): bool
    {
        return $this->scenario_id === null && filled($this->scenario_slug);
    }

    /**
     * Whether the recorded outcome still describes the scenario as it is now.
     *
     * A green tick against a scenario redrawn since is worse than no tick at
     * all: it is read as "this still works" by the very person who has just
     * changed the thing it was checking. Comparing fingerprints is what lets
     * the screen say "needs a rerun" instead of showing a verdict about a graph
     * that no longer exists.
     */
    public function needsRerun(?string $fingerprint): bool
    {
        if ($this->last_run_at === null) {
            return false;
        }

        return $this->last_fingerprint !== $fingerprint;
    }

    protected static function newFactory(): Factory
    {
        return QualityTestFactory::new();
    }
}
