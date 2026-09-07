<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row whose owner is one relation away.
 *
 * Stands in for every child table a normalised application has — a booking under
 * an order, a line under an invoice — and it earns its place by *not* having an
 * owner column. A data resource declared on it can only be scoped through
 * `record`, which is exactly the case the reader has to handle and the writer
 * has to refuse.
 */
class DemoLine extends Model
{
    protected $table = 'demo_lines';

    protected $guarded = [];

    /**
     * @return BelongsTo<DemoRecord, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(DemoRecord::class, 'demo_record_id');
    }
}
