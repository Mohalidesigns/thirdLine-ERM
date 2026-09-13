<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A service level with a target somebody can actually be measured against.
 *
 * `target_operator` IS STORED RATHER THAN INFERRED FROM THE METRIC NAME, and
 * that is the whole reason breach detection here is trustworthy. 99.9%
 * availability is a FLOOR — anything below it breaches — and a four-hour
 * resolution time is a CEILING. Guessing which from a label is how a detector
 * reports the exact opposite of the truth, and reports it confidently, month
 * after month, until someone checks by hand.
 */
class Sla extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_slas';

    public const OP_GTE = 'gte';

    public const OP_LTE = 'lte';

    public const OP_EQ = 'eq';

    /** @var list<string> */
    public const WINDOWS = ['daily', 'weekly', 'monthly', 'quarterly', 'annual'];

    /** @var list<string> */
    public const DATA_SOURCES = ['manual', 'import', 'api', 'connector'];

    protected $fillable = [
        'organization_id', 'contract_id', 'engagement_id', 'metric_code', 'metric_name',
        'unit', 'target_operator', 'target_value', 'measurement_window', 'data_source',
        'penalty_terms', 'credit_formula', 'is_active', 'created_by',
    ];

    protected $casts = [
        'target_value' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'measurement_window' => 'monthly',
        'data_source' => 'manual',
        'is_active' => true,
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SlaMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(SlaMeasurement::class, 'sla_id');
    }

    /**
     * Whether a measured value breaches this target.
     *
     * Floating-point equality is compared with a tolerance rather than `==`,
     * because a target stored as 99.9000 and a measurement of 99.9 differ in
     * the last bit and a naive comparison would report a breach on a value
     * that met the target exactly.
     */
    public function isBreach(float $actual): bool
    {
        $target = (float) $this->target_value;
        $epsilon = 0.00005;

        return match ($this->target_operator) {
            self::OP_GTE => $actual < $target - $epsilon,
            self::OP_LTE => $actual > $target + $epsilon,
            self::OP_EQ => abs($actual - $target) > $epsilon,
            default => false,
        };
    }

    /**
     * How far past the target a breach fell, as a share of the target.
     *
     * Used to propose a severity. Returned as a magnitude in both directions
     * so a floor and a ceiling produce comparable numbers, and null where the
     * target is zero — a percentage of zero is not a number, and dividing by
     * it would produce an INF that renders as a blank cell.
     */
    public function breachMagnitude(float $actual): ?float
    {
        $target = (float) $this->target_value;

        if (abs($target) < 0.00005 || ! $this->isBreach($actual)) {
            return null;
        }

        return round(abs($actual - $target) / abs($target), 4);
    }

    /**
     * A proposed severity for a breach — for a reviewer to confirm.
     *
     * Deliberately conservative: a single month 0.05% below a 99.9% target is
     * a low-severity miss, and a tool that calls it critical gets muted. The
     * REPEATED breach is what matters, and that is counted on the register
     * rather than inferred from one measurement.
     */
    public function proposedSeverity(float $actual): ?string
    {
        $magnitude = $this->breachMagnitude($actual);

        if ($magnitude === null) {
            return null;
        }

        return match (true) {
            $magnitude >= 0.25 => 'critical',
            $magnitude >= 0.10 => 'high',
            $magnitude >= 0.02 => 'medium',
            default => 'low',
        };
    }

    public function targetLabel(): string
    {
        $operator = match ($this->target_operator) {
            self::OP_GTE => 'at least',
            self::OP_LTE => 'no more than',
            self::OP_EQ => 'exactly',
            default => '',
        };

        return trim(sprintf(
            '%s %s%s',
            $operator,
            rtrim(rtrim((string) $this->target_value, '0'), '.'),
            $this->unit ? ' '.$this->unit : ''
        ));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
