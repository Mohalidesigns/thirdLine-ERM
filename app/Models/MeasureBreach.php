<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded crossing of a threshold band.
 *
 * Before WP-04 a KRI breach existed only as a notification: the nightly check
 * dispatched an event, someone got a bell icon, and that was the entire life of
 * the fact. Nothing could answer "how many limits are we over right now", "who
 * acknowledged this", or "how long does it take us to clear a red" — the three
 * questions a board risk committee actually asks about indicators.
 *
 * A breach is a register entry with a lifecycle, which is what makes mean time
 * to resolve computable at all.
 */
class MeasureBreach extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'measure_id',
        'object_id',
        'period_id',
        'breached_at',
        'band_from',
        'band_to',
        'value',
        'threshold_value',
        'severity',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'linked_risk_id',
        'linked_issue_id',
        'root_cause',
        'note',
    ];

    protected $casts = [
        'breached_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'value' => 'decimal:6',
        'threshold_value' => 'decimal:6',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function measure()
    {
        return $this->belongsTo(Measure::class);
    }

    public function object()
    {
        return $this->belongsTo(GraphObject::class, 'object_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Period, $this> */
    public function period(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function acknowledgedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function linkedRisk()
    {
        return $this->belongsTo(Risk::class, 'linked_risk_id');
    }

    public function linkedIssue()
    {
        return $this->belongsTo(Issue::class, 'linked_issue_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Hours from breach to acknowledgement; null while unacknowledged. */
    public function hoursToAcknowledge(): ?float
    {
        return $this->acknowledged_at
            ? round($this->breached_at->diffInMinutes($this->acknowledged_at) / 60, 2)
            : null;
    }

    /** Hours from breach to resolution; null while open. */
    public function hoursToResolve(): ?float
    {
        return $this->resolved_at
            ? round($this->breached_at->diffInMinutes($this->resolved_at) / 60, 2)
            : null;
    }

    /**
     * Mean time to resolve, in hours, over the resolved breaches in a query.
     *
     * Computed from the rows, not stored: a stored MTTR is a number that goes
     * stale the moment a breach is reopened.
     */
    public static function meanTimeToResolveHours(?Builder $query = null): ?float
    {
        $rows = ($query ?? static::query())->resolved()->whereNotNull('resolved_at')->get(['breached_at', 'resolved_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $total = $rows->sum(fn (self $breach) => $breach->breached_at->diffInMinutes($breach->resolved_at));

        return round($total / $rows->count() / 60, 2);
    }
}
