<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Database\Factories\ChannelConnectionFactory;

/**
 * The account one bot speaks from in one messenger.
 *
 * What a conversation knows is the channel it arrived on; what the driver needs
 * in order to answer is a token, and tokens belong to accounts rather than to
 * installations. This row is that account: the bot it answers as, the
 * credentials it answers with, and the address the provider delivers to.
 *
 * Credentials are encrypted as a whole rather than column by column. Every
 * provider wants a different set — a token here, a phone number id and an app
 * secret there, a community id and a confirmation string somewhere else — and
 * none of them is ever searched by, so columns would buy nothing and cost a
 * migration per messenger.
 */
class ChannelConnection extends Model
{
    /** @use HasFactory<ChannelConnectionFactory> */
    use HasFactory;

    protected $table = 'chatbot_channel_connections';

    /**
     * The provider connects to us and delivers each update as it happens.
     */
    public const DELIVERY_WEBHOOK = 'webhook';

    /**
     * We connect to the provider and ask what has arrived.
     *
     * For networks where the first way is impossible rather than merely
     * slower — see `ChannelPolling`.
     */
    public const DELIVERY_POLL = 'poll';

    protected $guarded = [];

    /**
     * Hidden from arrays and JSON, not only encrypted at rest.
     *
     * A connection is rendered on a Filament page like any other record, and
     * `toArray()` is what a careless `dd()`, a log line or a Livewire payload
     * reaches for. Encryption protects the database; this protects the places
     * the decrypted value would otherwise travel to.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'verified_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * Gives every new connection the address segment the provider will call.
     *
     * Generated here rather than asked of the form, because it is not a decision
     * anybody makes: it only has to be unguessable and stable. Set at creation
     * and never regenerated — the webhook registered on the provider's side
     * points at it, and changing it silently stops delivery.
     */
    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            if (blank($connection->public_id)) {
                $connection->public_id = (string) Str::lower(Str::random(32));
            }
        });
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The accounts the poller is responsible for.
     *
     * Active as well as polled: a connection switched off is one whose owner
     * has said it must not answer, and fetching its updates anyway would both
     * consume them from the provider's queue and hand them to a bot nobody
     * asked to run.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePolled(Builder $query): void
    {
        $query->active()->where('delivery', self::DELIVERY_POLL);
    }

    /**
     * One credential, trimmed, with absence and emptiness the same answer.
     *
     * A token saved as a blank line is the state a half-filled form leaves
     * behind, and a driver that treats it as present sends a request that can
     * only fail. The caller asking "is there a token" gets one answer for both.
     */
    public function credential(string $key): string
    {
        $credentials = $this->credentials ?? [];

        return trim((string) ($credentials[$key] ?? ''));
    }

    /**
     * Whether the provider could be reached with what is saved here.
     *
     * The required keys come from the driver, because only it knows what its
     * provider demands: a Telegram bot needs a token, a WhatsApp number needs
     * an id beside it, and a VK community needs a confirmation string that
     * nothing else in the package has a name for.
     *
     * @param  list<string>  $required
     */
    public function hasCredentials(array $required): bool
    {
        foreach ($required as $key) {
            if ($this->credential($key) === '') {
                return false;
            }
        }

        return true;
    }

    protected static function newFactory(): Factory
    {
        return ChannelConnectionFactory::new();
    }
}
