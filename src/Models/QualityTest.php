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

    protected static function newFactory(): Factory
    {
        return QualityTestFactory::new();
    }
}
