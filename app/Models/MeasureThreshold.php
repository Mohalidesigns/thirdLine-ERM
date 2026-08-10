<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Periods\DateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The RAG bands in force for a measure over a stretch of time.
 *
 * Effective-dated and append-only by convention: re-baselining writes a new row
 * with supersedes_id pointing at the old one and closes the old one's
 * effective_to. Nothing overwrites a band, because a breach recorded in March
 * has to keep meaning what it meant in March.
 *
 * A band bound may be a literal (`min`, `max`) or an expression
 * (`min_formula`, `max_formula`) evaluated by FormulaEvaluator. The expression
 * form is what makes a limit like "0.5% of qualifying capital" or an
 * inflation-indexed naira figure survive contact with a moving denominator —
 * see App\Services\ThresholdRebaselineService.
 */
class MeasureThreshold extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'measure_id',
        'object_id',
        'effective_from',
        'effective_to',
        'bands',
        'direction',
        'approved_by',
        'approved_at',
        'supersedes_id',
    ];

    protected $casts = [
        'bands' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
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

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy()
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        // Day-wide bounds: a band effective FROM the date itself must apply on
        // that date, and one closed ON the date must still apply that day.
        return $query->where('effective_from', '<=', DateBounds::endOfDay($date))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')
                ->orWhere('effective_to', '>=', DateBounds::startOfDay($date)));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Whether any band bound is an expression rather than a literal.
     *
     * The re-baselining job only has to look at these.
     */
    public function hasFormulaBounds(): bool
    {
        foreach ($this->bands ?? [] as $band) {
            if (($band['min_formula'] ?? null) !== null || ($band['max_formula'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The band a value falls into, as its band definition, or null when no band
     * claims it.
     *
     * Adjacent bands share a boundary — green's max IS amber's min, because the
     * KRI screens have always been configured with three numbers, not six. So
     * exactly one side of each band has to be open, and WHICH side depends on
     * the direction:
     *
     *   higher_worse   [min, max)   the boundary belongs to the WORSE band above
     *   lower_worse    (min, max]   the boundary belongs to the WORSE band below
     *
     * Both readings put a value sitting exactly on a limit into the worse of
     * the two bands, which is what a limit means, and both reproduce the
     * behaviour of the KriController::determineStatus ladder this replaces.
     *
     * A missing min is negative infinity; a missing max is positive infinity.
     *
     * @param  array<int, array<string, mixed>>|null  $bands  resolved bands, when formulas have already been evaluated
     * @return array<string, mixed>|null
     */
    public function bandFor(float $value, ?array $bands = null): ?array
    {
        $lowerWorse = $this->direction === 'lower_worse';

        foreach ($bands ?? $this->bands ?? [] as $band) {
            $min = $band['min'] ?? null;
            $max = $band['max'] ?? null;

            if ($min !== null && ($lowerWorse ? $value <= (float) $min : $value < (float) $min)) {
                continue;
            }

            if ($max !== null && ($lowerWorse ? $value > (float) $max : $value >= (float) $max)) {
                continue;
            }

            return $band;
        }

        return null;
    }

    /**
     * Band definitions ordered worst-first, so "the worst band this value is
     * in" is the first match.
     *
     * @return list<string>
     */
    public static function severityOrder(): array
    {
        return ['red', 'amber', 'yellow', 'green'];
    }
}
