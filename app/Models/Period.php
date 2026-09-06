<?php

namespace App\Models;

use App\Support\Periods\DateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A bounded stretch of time that values can be recorded against.
 *
 * is_closed is the load-bearing column. A closed period's measure_values are
 * locked, which is what lets a board pack assembled in April still reconcile in
 * October: the numbers behind it cannot have moved without a reopen that says
 * who did it and when.
 */
class Period extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'calendar_id',
        'code',
        'type',
        'name',
        'start_date',
        'end_date',
        'parent_period_id',
        'is_closed',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];

    /** Coarse-to-fine, for ordering a type selector. */
    public const TYPE_ORDER = ['year', 'half', 'quarter', 'month', 'week', 'day', 'custom'];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function calendar()
    {
        return $this->belongsTo(PeriodCalendar::class, 'calendar_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_period_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_period_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function closedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function values()
    {
        return $this->hasMany(MeasureValue::class, 'period_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Periods wholly inside [$from, $to], in chronological order.
     *
     * Bounds are widened to the whole day at each end — see DateBounds for why
     * a bare date string would drop the last period of the range.
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('start_date', '>=', DateBounds::startOfDay($from))
            ->where('end_date', '<=', DateBounds::endOfDay($to))
            ->orderBy('start_date');
    }

    /** Periods that contain the given date. */
    public function scopeContaining(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', DateBounds::endOfDay($date))
            ->where('end_date', '>=', DateBounds::startOfDay($date));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * This period and every period beneath it, as ids.
     *
     * A quarter's months, a year's quarters and months. Used when a measure is
     * aggregated up from a finer grain than it is being displayed at.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];

        // Bounded by the calendar's own depth (year -> half -> quarter ->
        // month), so this terminates in four passes rather than recursing per
        // node.
        while ($frontier !== []) {
            $ids = array_merge($ids, $frontier);
            $frontier = static::query()
                ->whereIn('parent_period_id', $frontier)
                ->pluck('id')
                ->all();
        }

        return $ids;
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_closed;
    }
}
