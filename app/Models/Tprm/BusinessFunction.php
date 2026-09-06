<?php

namespace App\Models\Tprm;

use App\Models\BusinessUnit;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A business function the institution performs — the DORA RT.06.01 model, and
 * the object AC-01 is enforced against.
 *
 * A function flagged `is_prohibited_outsourcing` hard-blocks intake: a bank may
 * not outsource its internal audit, compliance or company secretarial
 * functions. The citation travels on the row rather than in the error message,
 * because the prohibited set differs by licence type and a client must never
 * be shown a citation that does not apply to it.
 */
class BusinessFunction extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tp_business_functions';

    /**
     * The three criticality levels, ordered. `critical` plus an RTO of four
     * hours or less is the KO-CIF knockout condition.
     *
     * @var list<string>
     */
    public const CRITICALITIES = ['standard', 'important', 'critical'];

    protected $fillable = [
        'organization_id', 'function_code', 'name', 'description', 'owning_business_unit_id',
        'licensed_activity', 'criticality', 'criticality_rationale', 'criticality_assessed_at',
        'mtpd_hours', 'rto_hours', 'rpo_hours', 'mbco', 'impact_of_discontinuation',
        'is_prohibited_outsourcing', 'prohibition_citation', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'criticality_assessed_at' => 'date',
        'is_prohibited_outsourcing' => 'boolean',
        'is_active' => 'boolean',
        'mtpd_hours' => 'integer',
        'rto_hours' => 'integer',
        'rpo_hours' => 'integer',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function owningBusinessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'owning_business_unit_id');
    }

    /** @return BelongsToMany<Engagement, $this> */
    public function engagements(): BelongsToMany
    {
        return $this->belongsToMany(Engagement::class, 'tp_engagement_functions', 'business_function_id', 'engagement_id')
            ->withPivot(['dependency_level', 'reliance_level'])
            ->withTimestamps();
    }

    /**
     * Whether this function is a "critical or important function" in the DORA
     * Art. 3(22) sense — the test the KO-CIF knockout and the exit-plan
     * requirement both read.
     */
    public function isCriticalOrImportant(): bool
    {
        return in_array($this->criticality, ['critical', 'important'], true);
    }
}
