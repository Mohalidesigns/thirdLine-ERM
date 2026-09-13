<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * What a tier MEANS for this tenant — assessment cadence, screening cadence,
 * approval chain, clause set, exit-plan requirement, remediation SLAs.
 *
 * Everything downstream of tiering reads this row rather than a constant, so
 * that "Critical" is a policy a risk committee owns rather than a number in
 * the code. Config supplies the defaults for a tenant that has not set one.
 */
class TierPolicy extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_tier_policies';

    /** @var list<string> */
    public const MONITORING_INTENSITIES = ['none', 'passive', 'active', 'continuous'];

    protected $fillable = [
        'organization_id', 'tier', 'assessment_template_ids', 'assessment_frequency_months',
        'screening_frequency_months', 'monitoring_intensity', 'approval_chain',
        'required_clause_set_id', 'exit_plan_required', 'exit_test_frequency_months',
        'site_visit_required', 'board_reportable', 'remediation_sla', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tier' => RiskTier::class,
        'assessment_template_ids' => 'array',
        'approval_chain' => 'array',
        'remediation_sla' => 'array',
        'exit_plan_required' => 'boolean',
        'site_visit_required' => 'boolean',
        'board_reportable' => 'boolean',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Remediation SLA in days for a severity under this tier, falling back to
     * the product default where the tenant has not set one.
     *
     * A Medium finding against a Critical vendor is not the same ninety days
     * as a Medium against a Low one, which is why the lookup is per tier and
     * not a global table.
     */
    public function slaDaysFor(FindingSeverity $severity): int
    {
        $configured = $this->remediation_sla[$severity->value] ?? null;

        return is_numeric($configured) ? (int) $configured : $severity->defaultSlaDays();
    }
}
