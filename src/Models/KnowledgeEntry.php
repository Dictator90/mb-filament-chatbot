<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MB\FilamentChatbot\Database\Factories\KnowledgeEntryFactory;
use MB\FilamentChatbot\Enums\KnowledgeMode;

/**
 * One topic the bot knows about.
 *
 * A topic is not always an answer. The mode decides whether the recorded text is
 * sent, flagged for checking, withheld entirely, or replaced by a scenario, and
 * the entries that withhold are as valuable as the ones that answer: they are the
 * only record of which questions a bot must not improvise on.
 *
 * @property string $topic_key
 * @property KnowledgeMode $mode
 * @property array<int, string>|null $keywords
 * @property array<int, string>|null $variants
 * @property string|null $scenario_slug
 * @property bool $is_active
 */
class KnowledgeEntry extends Model
{
    /** @use HasFactory<KnowledgeEntryFactory> */
    use HasFactory;

    protected $table = 'chatbot_knowledge_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mode' => KnowledgeMode::class,
            'keywords' => 'array',
            'variants' => 'array',
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
     * The text a topic is matched against, in one definition.
     *
     * The question, the keywords and the variants — and deliberately **not** the
     * answer. An answer is a paragraph of prose written for a person, full of
     * incidental words, and matching on it makes every long topic a better fit
     * for every question than the short topic that is actually about it.
     *
     * Here rather than at either call site because there are two: the finder
     * that picks the one topic to answer with, and the source that gathers
     * material for the model. Two copies of "what a topic is about" drift, and
     * the drift shows up as the base answering one way through a scenario and
     * another way through the model — with both screens insisting they are
     * reading the same knowledge base.
     *
     * @return list<string>
     */
    public function searchableTexts(): array
    {
        return array_values(array_merge(
            [(string) $this->question],
            array_map(static fn (mixed $word): string => (string) $word, (array) $this->keywords),
            array_map(static fn (mixed $word): string => (string) $word, (array) $this->variants),
        ));
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
     * Entries a given bot may use: its own, plus the ones shared by every bot.
     *
     * The shared entries are included rather than left to a second query because
     * forgetting them is invisible — the bot simply does not know something, and
     * nothing anywhere reports a topic that failed to be considered.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBot(Builder $query, ?int $botId): Builder
    {
        return $query->where(function (Builder $query) use ($botId): void {
            $query->whereNull('bot_id');

            if ($botId !== null) {
                $query->orWhere('bot_id', $botId);
            }
        });
    }

    /**
     * Topics that hand over to a scenario instead of answering.
     *
     * Both halves are required: a mode with no slug is an entry that leads
     * nowhere, and it would take the conversation with it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLeadingToScenario(Builder $query, ?string $slug = null): Builder
    {
        $query->where('mode', KnowledgeMode::Scenario->value)
            ->whereNotNull('scenario_slug');

        if ($slug !== null) {
            $query->where('scenario_slug', $slug);
        }

        return $query;
    }

    protected static function newFactory(): Factory
    {
        return KnowledgeEntryFactory::new();
    }
}
