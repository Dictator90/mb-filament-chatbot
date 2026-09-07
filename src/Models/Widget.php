<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Database\Factories\WidgetFactory;

/**
 * One placement of a bot on one set of sites.
 *
 * A bot is a personality; a widget is where that personality is put and what it
 * looks like there. The same support bot goes on the main site in one colour and
 * on a partner's portal in another, with a different launcher label and a
 * different list of sites allowed to embed it — one bot, two widgets.
 *
 * The key is the only part of this that leaves the building. It goes into a
 * script tag on somebody else's page, so it is opaque on purpose: which bot
 * answers behind it is a setting here, and can be changed without anybody
 * editing that page.
 */
class Widget extends Model
{
    /** @use HasFactory<WidgetFactory> */
    use HasFactory;

    protected $table = 'chatbot_widgets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allowed_origins' => 'array',
            'theme' => 'array',
            'labels' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return WidgetFactory::new();
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function theme(): WidgetTheme
    {
        return WidgetTheme::make((array) $this->theme);
    }

    /**
     * Which bot answers here, or none.
     *
     * A widget whose bot has been switched off answers as a widget with no bot:
     * the conversation opens and goes straight to a person. The alternative —
     * letting a disabled bot answer because a widget still names it — would make
     * "switched off" mean nothing on the one channel where visitors actually
     * arrive.
     */
    public function answeringBotId(): ?int
    {
        $bot = $this->bot;

        return $bot !== null && $bot->is_active === true ? (int) $bot->getKey() : null;
    }

    /**
     * Whether this widget may be embedded on the site the request came from.
     *
     * Narrows the installation-wide allow-list rather than replacing it: that
     * list says which sites may talk to this application at all and fails closed,
     * and this one says which of them this particular widget belongs on. An empty
     * list here therefore means "wherever the installation allows" and not
     * "nowhere" — otherwise every widget would go dark the moment somebody added
     * the field without filling it in.
     */
    public function reaches(?string $origin): bool
    {
        $origins = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->allowed_origins,
        ), static fn (string $value): bool => $value !== ''));

        if ($origins === []) {
            return true;
        }

        $origin = trim((string) $origin);

        return $origin !== '' && in_array($origin, $origins, true);
    }
}
