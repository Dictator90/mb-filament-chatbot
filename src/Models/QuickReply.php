<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Database\Factories\QuickReplyFactory;

/**
 * A phrase an operator drops into the conversation instead of retyping it.
 *
 * @property string $title
 * @property string $body
 * @property string|null $shortcut
 * @property int|null $user_id
 * @property int $sort
 * @property bool $is_active
 */
class QuickReply extends Model
{
    /** @use HasFactory<QuickReplyFactory> */
    use HasFactory;

    protected $table = 'chatbot_quick_replies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
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
     * What one operator may insert: the shared replies plus their own.
     *
     * The condition is grouped rather than chained. Left flat, an "or" here would
     * swallow whatever the caller had already constrained — an active-only filter
     * most of all — and the picker would start offering replies that were
     * switched off on purpose.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailableTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId): void {
            $query->whereNull('user_id');

            if ($userId !== null) {
                $query->orWhere('user_id', $userId);
            }
        });
    }

    protected static function newFactory(): Factory
    {
        return QuickReplyFactory::new();
    }
}
