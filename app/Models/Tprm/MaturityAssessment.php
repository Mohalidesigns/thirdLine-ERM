<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A programme maturity self-assessment for one period — FR-RPT-06.
 *
 * `framework_version` IS ON THE ROW, not in config, because a level 3 recorded
 * under one rubric and a level 3 recorded under another are not the same
 * claim, and the trend across periods puts them on the same line. Stamping the
 * version is what makes that line honest when the criteria are revised.
 *
 * APPROVAL FREEZES IT, for the same reason a board pack freezes: a maturity
 * trend whose earlier points move is not a trend.
 */
class MaturityAssessment extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_maturity_assessments';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'organization_id', 'period_label', 'as_at', 'summary', 'created_by', 'updated_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = [
        'status', 'framework_version', 'assessed_by', 'assessed_at', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'as_at' => 'date',
        'assessed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /** @return HasMany<MaturityScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(MaturityScore::class, 'assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isEditable(): bool
    {
        return ! $this->isApproved();
    }
}
