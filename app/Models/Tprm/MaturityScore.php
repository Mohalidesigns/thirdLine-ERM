<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use App\Support\Tprm\MaturityModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One category's score inside a maturity assessment — FR-RPT-06.
 *
 * `current_level` IS NULLABLE AND NULL IS NOT ZERO. Level 0 means
 * "non-existent" — a real finding about a programme somebody looked at. Null
 * means nobody has looked. A model that collapsed the two would let an
 * unstarted programme and an unassessed one report identically, and the second
 * is the one an auditor asks about.
 *
 * THE GAP IS A PLAN, NOT A NUMBER. `target_level` alone says where the
 * programme wants to be; `gap_actions`, `owner_id` and `target_date` are what
 * make it a commitment somebody can be asked about next quarter.
 */
class MaturityScore extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_maturity_scores';

    protected $fillable = [
        'organization_id', 'assessment_id', 'framework', 'category_code', 'category_name',
        'current_level', 'target_level', 'evidence', 'gap_actions', 'owner_id', 'target_date',
        'updated_by',
    ];

    protected $casts = [
        'current_level' => 'integer',
        'target_level' => 'integer',
        'target_date' => 'date',
    ];

    /** @return BelongsTo<MaturityAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(MaturityAssessment::class, 'assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currentLabel(): string
    {
        return MaturityModel::levelLabel($this->current_level);
    }

    public function targetLabel(): string
    {
        return MaturityModel::levelLabel($this->target_level);
    }

    /**
     * How far short of its own target this category is.
     *
     * Null where either end is unscored — a gap computed against a target
     * nobody set is a number with no meaning, and a zero there would read as
     * "on target".
     */
    public function gap(): ?int
    {
        if ($this->current_level === null || $this->target_level === null) {
            return null;
        }

        return max(0, $this->target_level - $this->current_level);
    }
}
