<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The vendor taxonomy, and the join to the ERM key risk areas.
 *
 * `ermRiskCategory()` is how third-party exposure reaches the register's own
 * risk structure: every vendor category points at a node of `risk_categories`,
 * so "what proportion of our operational risk area is third-party exposure" is
 * a query rather than a quarterly spreadsheet. Nullable, because a tenant
 * whose taxonomy has no third-party node yet must still be able to use the
 * module — the roll-up reports those engagements as uncategorised, which is a
 * true statement, rather than assigning them somewhere plausible.
 */
class Category extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tp_categories';

    protected $fillable = [
        'organization_id', 'parent_id', 'erm_risk_category_id', 'code', 'name', 'description',
        'default_tier_floor', 'is_ict', 'is_prohibited_outsourcing', 'prohibition_citation',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_ict' => 'boolean',
        'is_prohibited_outsourcing' => 'boolean',
        'is_active' => 'boolean',
        'default_tier_floor' => RiskTier::class,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * The ERM key risk area this category's exposure rolls up into.
     *
     * @return BelongsTo<RiskCategory, $this>
     */
    public function ermRiskCategory(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'erm_risk_category_id');
    }

    /** @return HasMany<ThirdParty, $this> */
    public function thirdParties(): HasMany
    {
        return $this->hasMany(ThirdParty::class, 'category_id');
    }
}
