<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

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

    /** Hours from breach to acknowledgement; null while unacknowledged, or if the interval is impossible. */
    public function hoursToAcknowledge(): ?float
    {
        return self::hoursBetween($this->breached_at, $this->acknowledged_at);
    }

    /** Hours from breach to resolution; null while open, or if the interval is impossible. */
    public function hoursToResolve(): ?float
    {
        return self::hoursBetween($this->breached_at, $this->resolved_at);
    }

    /**
     * Mean time to resolve, in hours, over the resolved breaches in a query.
     *
     * Computed from the rows, not stored: a stored MTTR is a number that goes
     * stale the moment a breach is reopened. A row whose resolution precedes its
     * breach is unmeasurable and is left out of numerator AND denominator
     * (ADR 0027): neither clamped to zero, which would invent "resolved
     * instantly", nor allowed to pull the mean down as a negative.
     */
    public static function meanTimeToResolveHours(?Builder $query = null): ?float
    {
        $rows = ($query ?? static::query())->resolved()->whereNotNull('resolved_at')->get(['breached_at', 'resolved_at']);

        $minutes = $rows
            ->map(fn (self $breach) => self::minutesBetween($breach->breached_at, $breach->resolved_at))
            ->filter(fn (?float $m) => $m !== null);

        if ($minutes->isEmpty()) {
            return null;
        }

        return round($minutes->sum() / $minutes->count() / 60, 2);
    }

    /**
     * Minutes (fractional, as Carbon 3 returns them) from $from to $to, or null when either is absent or $to is
     * before $from. An interval that ends before it starts is not a short one;
     * it is not a measurement.
     */
    private static function minutesBetween(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null || $to->lessThan($from)) {
            return null;
        }

        return (float) $from->diffInMinutes($to);
    }

    private static function hoursBetween(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        $minutes = self::minutesBetween($from, $to);

        return $minutes === null ? null : round($minutes / 60, 2);
    }
}
