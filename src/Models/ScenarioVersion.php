<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MB\FilamentChatbot\Database\Factories\ScenarioVersionFactory;
use MB\FilamentChatbot\Models\Concerns\ResolvesOperatorModel;

/**
 * A frozen snapshot of a scenario that was, or is, live.
 *
 * Written once and not edited afterwards: editing a published version would be
 * rewriting the history of what the bot actually said to people.
 *
 * A rollback does not create a version, it makes an existing row live again.
 * Otherwise the numbers would climb from nothing but walking back and forth
 * through the history, and "roll back to three" would stop meaning anything by
 * the end of the day.
 *
 * @property int $id
 * @property int $scenario_id
 * @property int $number
 * @property array<string, mixed> $graph
 * @property array<string, mixed>|null $pins
 * @property string $fingerprint
 * @property string|null $note
 * @property Carbon|null $published_at
 * @property int|null $published_by_id
 */
class ScenarioVersion extends Model
{
    /** @use HasFactory<ScenarioVersionFactory> */
    use HasFactory;

    use ResolvesOperatorModel;

    protected $table = 'chatbot_scenario_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'graph' => 'array',
            'pins' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Whether this is the version answering customers right now.
     *
     * Answered by comparing with the scenario's column instead of carrying a
     * flag of its own. A scenario has exactly one live version, and storing
     * that twice would admit a state where two rows both claim to be it — with
     * nothing to say which one the bot is really using.
     */
    public function isLive(): bool
    {
        $this->loadMissing('scenario');

        return $this->scenario?->live_version_id === $this->getKey();
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    /**
     * Who published it. Empty when that person is gone from the application:
     * the version outlives their account, because it describes what the
     * business said to its customers and not who was employed at the time.
     *
     * The class comes from the shared resolver rather than being named here,
     * because operators are the host's table and the same answer has to serve
     * the conversation's assignee, the message's author and this. Resolved
     * separately in each place, it would eventually be changed in only one.
     *
     * @return BelongsTo<Model, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(self::operatorModel(), 'published_by_id');
    }

    protected static function newFactory(): Factory
    {
        return ScenarioVersionFactory::new();
    }
}
