<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded exchange rate.
 *
 * rate_type is not decoration. In Nigeria the CBN official window, the NAFEM
 * (formerly I&E) window and the parallel market can differ by a wide margin at
 * the same instant, and which one a figure was converted at changes whether a
 * loss event crosses a reporting threshold. Every converted measure_value
 * records the rate it used, and this table records where that rate came from.
 *
 * organization_id NULL is a platform rate — what CBN published that day —
 * visible to every tenant. A tenant may record its own internal or contracted
 * rate with the same currency pair and date.
 */
class FxRate extends Model
{
    use BelongsToOrganization, HasFactory;

    /** Platform rates carry a NULL organization_id and belong to everyone. */
    protected bool $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'from_currency',
        'to_currency',
        'rate_date',
        'rate_type',
        'rate',
        'source',
        'captured_at',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:8',
        'captured_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // organization_key shadows the nullable organization_id so the natural
        // key is actually unique — a NULL never equals a NULL in a MySQL unique
        // index. 0 is not a valid organizations.id.
        static::saving(function (self $model): void {
            $model->organization_key = (int) ($model->organization_id ?? 0);
        });
    }
}
