<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MB\FilamentChatbot\Database\Factories\BotFactory;

/**
 * One assistant: its voice, its working hours and its budget.
 *
 * An installation may run several — a public one on the website and an internal
 * one for staff — which is why the prompt, the model and the origin list live on
 * the row rather than in configuration. Configuration is the fallback for the
 * fields left empty here, not the other way round.
 */
class Bot extends Model
{
    /** @use HasFactory<BotFactory> */
    use HasFactory;

    protected $table = 'chatbot_bots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public_widget' => 'boolean',
            'allowed_origins' => 'array',
            'monthly_token_budget' => 'integer',
            'monthly_cost_budget' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return BotFactory::new();
    }

    /**
     * @return HasMany<Scenario, $this>
     */
    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class, 'bot_id');
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'bot_id');
    }

    /**
     * @return HasMany<KnowledgeEntry, $this>
     */
    public function knowledgeEntries(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'bot_id');
    }

    /**
     * @param  Builder<Bot>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Is the bot allowed to answer right now.
     *
     * Empty hours mean "always", never "never". The fields start out empty on
     * every bot ever created, and the opposite reading would ship a package
     * whose bots are mute until someone finds the two inputs that explain why —
     * a silence with no error anywhere to point at it.
     *
     * A window whose end is before its start crosses midnight (22:00 to 06:00)
     * and is honoured as such. Compared naively it would match nothing, and a
     * night shift's bot would go quiet exactly when it was configured to work.
     */
    public function isWorkingHours(): bool
    {
        $from = $this->normalizeTime($this->hours_from);
        $till = $this->normalizeTime($this->hours_till);

        if ($from === null || $till === null) {
            return true;
        }

        $now = now()->format('H:i:s');

        if ($from <= $till) {
            return $now >= $from && $now <= $till;
        }

        return $now >= $from || $now <= $till;
    }

    /**
     * Pads a stored time to 'HH:MM:SS' so that the comparison above is a plain
     * string comparison. Drivers hand back 'HH:MM:SS', a form hands back
     * 'HH:MM', and '09:30' > '10:00:00' is true — the sort of mismatch that
     * shows up as a bot answering at the wrong hour and nothing else.
     */
    protected function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = explode(':', trim($value));

        return sprintf(
            '%02d:%02d:%02d',
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            (int) ($parts[2] ?? 0),
        );
    }
}
