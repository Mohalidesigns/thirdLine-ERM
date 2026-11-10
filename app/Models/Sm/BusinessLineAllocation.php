<?php

namespace App\Models\Sm;

use App\Models\BusinessUnit;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One business line's share of a scenario (ADR 0027 Amendment 1 §A1.5).
 *
 * `business_units`, NOT `business_processes` — and the architect refused the
 * latter with reasons the coordinator upheld, so the allocation picker offers
 * tenant business units ONLY (addendum-4). The workbook's own rows are insurer
 * business lines (Property and Casualty / Life and Savings / Health / Investment
 * / Pensions), which is exactly why the FK reaches the tenant's own tree and
 * never a fixed list; `business_processes` is a process catalogue consumed by
 * RCSA and mirrored again by BCMS, so pointing allocations at it would put two
 * org trees on one screen.
 *
 * NO SOFT DELETES: a child line table (ADR 0026 §2).
 *
 * SUM-TO-100 IS A SERVICE INVARIANT, NOT A DB CHECK, and it is enforced at the
 * `in_design → designed` transition rather than on every save — a draft
 * legitimately sums to 60% mid-entry, and refusing to save it pushes users into
 * one big form. See `App\Services\Sm\AllocationService`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property int $business_unit_id
 * @property string $percent
 */
class BusinessLineAllocation extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_business_line_allocations';

    /**
     * The scale of `percent`, which is `decimal(5,2)` — the same precision as
     * `risks.control_effectiveness_pct`. Every sum-to-100 comparison is made at
     * THIS scale and never with float `==` (§A1.5).
     */
    public const PERCENT_SCALE = 2;

    /** What the allocation lines must add to, at PERCENT_SCALE. */
    public const REQUIRED_TOTAL = '100.00';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'business_unit_id',
        'percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'sm_scenario_id' => 'integer',
            'business_unit_id' => 'integer',
            // decimal:2, so the value is a STRING at the column's precision.
            // Spec 01 §4.5 requires decimals to reach the browser as strings:
            // a float that has been through json_encode can arrive as `100`
            // instead of `100.00`, and summing those in JS reintroduces exactly
            // the artefact the server was told to avoid.
            'percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }
}
