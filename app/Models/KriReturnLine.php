<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One KRI due in a return (ADR 0026 section 4) - the DRAFT STORE, not a
 * readings table. Nothing here is read as a reading by any dashboard, sweep or
 * report; a draft cannot raise a breach because it never reaches the measure
 * engine. `band` is for display only and never authoritative.
 */
class KriReturnLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'kri_return_lines';

    protected $fillable = [
        'organization_id',
        'kri_return_id',
        'key_risk_indicator_id',
        'period_id',
        'value',
        'comment',
        'root_cause',
        'action_point',
        'timeline',
        'action_owner_id',
        'timeline_change_reason',
        'band',
        'measure_value_id',
        'measure_breach_id',
        'issue_id',
        'saved_by',
        'saved_at',
    ];

    protected $casts = [
        'value' => 'decimal:6',
        'timeline' => 'date',
        'saved_at' => 'datetime',
    ];

    /** @return BelongsTo<KriReturn, $this> */
    public function kriReturn(): BelongsTo
    {
        return $this->belongsTo(KriReturn::class, 'kri_return_id');
    }

    /** @return BelongsTo<KeyRiskIndicator, $this> */
    public function kri(): BelongsTo
    {
        return $this->belongsTo(KeyRiskIndicator::class, 'key_risk_indicator_id')->withTrashed();
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<MeasureBreach, $this> */
    public function breach(): BelongsTo
    {
        return $this->belongsTo(MeasureBreach::class, 'measure_breach_id');
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }
}
