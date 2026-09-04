<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
