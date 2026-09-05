<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bot's model spending in one calendar month.
 *
 * Reading only. Every write goes through BudgetGuard, which changes the
 * counters with conditional SQL rather than with a loaded model: read-add-save
 * on a model is exactly the race the guard exists to prevent, and a second way
 * of writing these columns would be a way around it.
 *
 * @property int $id
 * @property int|null $bot_id
 * @property string $period
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micro
 * @property int $calls
 */
class BudgetPeriod extends Model
{
    protected $table = 'chatbot_budget_periods';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micro' => 'integer',
            'calls' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    /**
     * Everything spent under one bot, or under none at all.
     *
     * The null case is addressed with whereNull rather than with an equality
     * test, because `bot_id = null` matches nothing in SQL and the spending
     * that belongs to no bot is precisely the spending nobody thinks to check.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBot(Builder $query, Bot|int|null $bot): Builder
    {
        $botId = $bot instanceof Bot ? $bot->getKey() : $bot;

        return $botId === null
            ? $query->whereNull('bot_id')
            : $query->where('bot_id', $botId);
    }

    /**
     * The tokens counted against the monthly token budget.
     *
     * Input and output together, because that is what a provider bills and what
     * a person setting the budget has in mind. Counting only the prompt would
     * make a bot that answers at length look half as expensive as it is.
     */
    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }
}
