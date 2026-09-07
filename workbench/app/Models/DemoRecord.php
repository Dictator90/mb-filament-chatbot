<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A record belonging to the host application, and nothing more.
 *
 * It stands in for whatever the installation actually has — a customer, a
 * ticket, an order — precisely by being none of them. If a chat screen or a bot
 * step ever needs this class to have a particular field, that is the package
 * reaching into the host's business, which is the one thing it must not do.
 */
class DemoRecord extends Model
{
    protected $table = 'demo_records';

    protected $guarded = [];

    /**
     * An appended attribute, present precisely because it is a nuisance.
     *
     * A host model is free to append anything it likes, and whatever it appends
     * turns up in `toArray()` under a name that exists in no migration and in no
     * declared field list. That makes it the cheapest way there is for a reader
     * to hand out a value nobody approved, and the only way to prove a reader
     * does not is to point one at a model that has such an attribute.
     *
     * @var array<int, string>
     */
    protected $appends = ['shouted_name'];

    protected function getShoutedNameAttribute(): string
    {
        return mb_strtoupper((string) ($this->attributes['name'] ?? ''));
    }

    /**
     * @return HasMany<DemoLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DemoLine::class, 'demo_record_id');
    }

    /**
     * A public method that is not a relation, and must never be called.
     *
     * It is here to be dangerous. Anything that discovered a model's relations
     * by calling its public methods and looking at what came back would call
     * this one — and on a real host model the equivalent sends the e-mail,
     * charges the card or cancels the booking. The resource form reads declared
     * return types instead, so this is skipped without being touched, and this
     * method is what says so out loud if that ever changes.
     */
    public function chargeTheCard(): string
    {
        throw new RuntimeException('Something called a method that is not a relation.');
    }
}
