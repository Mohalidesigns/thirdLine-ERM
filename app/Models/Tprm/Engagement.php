<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\EngagementType;
use App\Enums\Tprm\RiskBand;
use App\Enums\Tprm\RiskTier;
use App\Models\BusinessUnit;
use App\Models\Concerns\HasObjectIdentity;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An engagement — a service bought from a third party, and the object risk is
 * assessed against (TRD §5.1).
 *
 * `effective_tier`, `residual_score`, `residual_band` and `data_confidence`
 * are STORED, and nothing outside the scoring service writes them. They are
 * not accessors, and the reason is not performance: TRD §7.9 requires that a
 * score never be recomputed retrospectively. An accessor recomputes on every
 * read, so the board pack printed in March reprints differently in June with
 * nothing recording that it changed, and AC-15's requirement that two users
 * see identical derivations becomes untestable.
 *
 * `effectiveTier()` is the exception, and it is a pure function of three
 * stored values rather than a recomputation: TRD §7.3's
 * `max(tier_from_score, knockout_floor, override_floor)`. It exists so that a
 * caller cannot reimplement the max and get the direction wrong — a knockout
 * and an override raise a tier and never lower it.
 */
class Engagement extends Model
{
    use BelongsToOrganization, HasObjectIdentity, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_engagements';

    /** @var list<string> */
    public const CLOUD_MODELS = ['none', 'iaas', 'paas', 'saas', 'hybrid'];

    /**
     * NDPA §41(2) lawful bases for a cross-border transfer, plus `none`.
     *
     * `none` is a value rather than a null on purpose: an engagement that
     * transfers personal data abroad with no recorded basis is the KO-PII-XB
     * knockout condition, and the absence of a basis is the finding. A null
     * would read as "not yet answered", which is a different state.
     *
     * @var list<string>
     */
    public const TRANSFER_BASES = [
        'ndpa_41_law', 'bcr', 'scc', 'code_of_conduct', 'certification', 'derogation_43', 'none',
    ];

    /** @var list<string> */
    public const SUBSTITUTABILITY = ['many', 'several', 'few', 'sole'];

    protected $fillable = [
        'organization_id', 'third_party_id', 'reference', 'name', 'service_description',
        'service_type_id', 'engagement_type', 'business_unit_id', 'relationship_owner_id',
        'executive_sponsor_id', 'status', 'start_date', 'end_date', 'annual_spend_minor', 'currency',
        'is_material_outsourcing', 'supports_critical_function', 'cloud_model', 'deployment_location',
        'pci_in_scope', 'processes_personal_data', 'data_subject_volume_band', 'data_categories',
        'data_location_at_rest', 'data_location_processing', 'cross_border', 'transfer_basis',
        'transfer_basis_note', 'dpia_required', 'dpia_id', 'substitutability', 'time_to_replace_months',
        'exit_plan_required', 'terminated_at', 'termination_reason', 'created_by', 'updated_by',
    ];

    /**
     * Score columns are NOT fillable.
     *
     * Every one of them is written by the scoring service through an explicit
     * `forceFill` or a targeted update, never by a mass assignment from a
     * request. A residual score that a form post can set is a residual score
     * a client can set, and the module's entire argument is that it cannot.
     *
     * @var list<string>
     */
    public const SCORE_COLUMNS = [
        'inherent_score', 'inherent_tier', 'effective_tier', 'residual_score', 'residual_band',
        'assurance_coverage', 'evidence_confidence', 'data_confidence',
        'tier_override', 'tier_override_reason', 'tier_override_approver_id', 'tier_override_expires_at',
        'next_assessment_due', 'next_review_due', 'erm_risk_id', 'erm_synced_at',
    ];

    /**
     * Defaults declared on the MODEL as well as in the migration.
     *
     * A default that lives only in the database is applied by the database,
     * which means `$engagement->status` is null on a freshly created model
     * while the row already says `draft`. Every caller in the request that
     * created it then reads a null status — and the bug only shows up on the
     * path nobody re-queries.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_material_outsourcing' => false,
        'supports_critical_function' => false,
        'pci_in_scope' => false,
        'processes_personal_data' => false,
        'cross_border' => false,
        'dpia_required' => false,
        'exit_plan_required' => false,
    ];

    protected $casts = [
        'status' => EngagementStatus::class,
        'engagement_type' => EngagementType::class,
        'inherent_tier' => RiskTier::class,
        'tier_override' => RiskTier::class,
        'effective_tier' => RiskTier::class,
        'residual_band' => RiskBand::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'tier_override_expires_at' => 'date',
        'next_assessment_due' => 'date',
        'next_review_due' => 'date',
        'terminated_at' => 'datetime',
        'erm_synced_at' => 'datetime',
        'annual_spend_minor' => 'integer',
        'time_to_replace_months' => 'integer',
        'is_material_outsourcing' => 'boolean',
        'supports_critical_function' => 'boolean',
        'pci_in_scope' => 'boolean',
        'processes_personal_data' => 'boolean',
        'cross_border' => 'boolean',
        'dpia_required' => 'boolean',
        'exit_plan_required' => 'boolean',
        'data_categories' => 'array',
        'inherent_score' => 'decimal:2',
        'residual_score' => 'decimal:2',
        'assurance_coverage' => 'decimal:3',
        'evidence_confidence' => 'decimal:3',
        'data_confidence' => 'decimal:3',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'service_type_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function relationshipOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function executiveSponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executive_sponsor_id');
    }

    /** @return BelongsToMany<BusinessFunction, $this> */
    public function businessFunctions(): BelongsToMany
    {
        return $this->belongsToMany(BusinessFunction::class, 'tp_engagement_functions', 'engagement_id', 'business_function_id')
            ->withPivot(['dependency_level', 'reliance_level'])
            ->withTimestamps();
    }

    /** @return HasMany<InherentAssessment, $this> */
    public function inherentAssessments(): HasMany
    {
        return $this->hasMany(InherentAssessment::class, 'engagement_id');
    }

    /** @return HasOne<InherentAssessment, $this> */
    public function currentInherentAssessment(): HasOne
    {
        return $this->hasOne(InherentAssessment::class, 'engagement_id')->where('is_current', true);
    }

    /** @return HasMany<ScoreRun, $this> */
    public function scoreRuns(): HasMany
    {
        return $this->hasMany(ScoreRun::class, 'engagement_id')->latest('created_at');
    }

    /**
     * The ERM register risk this engagement is represented by (TRD §15).
     *
     * Populated once the engagement tiers High or Critical, or carries a
     * Critical finding. The sync is one way — TPRM computes the score, the
     * register carries the treatment — because two engines writing one number
     * produce a number neither can explain.
     *
     * @return BelongsTo<Risk, $this>
     */
    public function ermRisk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'erm_risk_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Derived reads                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * `max(tier_from_score, knockout_floor, override_floor)` — TRD §7.3.
     *
     * Reads the stored `inherent_tier` (which already carries any knockout
     * floor applied at computation time) against the manual override, and
     * returns the higher. An expired override is ignored: an exception with an
     * end date that has passed is not an exception any more.
     */
    public function effectiveTier(): ?RiskTier
    {
        $computed = $this->inherent_tier;

        $override = $this->tier_override;
        $expiry = $this->tier_override_expires_at;

        if ($override !== null && ($expiry === null || ! $expiry->isPast())) {
            return $override->max($computed);
        }

        return $computed;
    }

    /** Whether this engagement belongs in the CBN and DORA ICT registers. */
    public function isIctArrangement(): bool
    {
        return $this->engagement_type?->isIctArrangement()
            ?? false;
    }

    /* ------------------------------------------------------------------ */
    /*  Derived facts for the knockout rules                               */
    /* ------------------------------------------------------------------ */

    /*
     * The next three read tables whose Eloquent models belong to later phases
     * — contracts to Phase 4, connections and access grants to Phase 7. They
     * are queried directly rather than modelled early, because a half-designed
     * model written now to satisfy one boolean is a model the owning phase has
     * to unpick.
     *
     * Each query is EXPLICITLY SCOPED TO organization_id. The query builder has
     * no global scope, so a query written here without it would read across
     * every tenant — the exact hole `BelongsToOrganization` exists to close.
     */

    /**
     * Whether an executed, unexpired contract exists — the KO-NOCONTRACT
     * condition, and the gate FR-CTR-05 builds on.
     */
    public function hasExecutedContract(): bool
    {
        return DB::table('tp_contracts')
            ->where('organization_id', $this->organization_id)
            ->where('engagement_id', $this->getKey())
            ->where('status', 'executed')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString());
            })
            ->exists();
    }

    /**
     * Whether any open connection reaches the core banking system or the
     * payment switch — one half of KO-CORE-CONN, the other being the intake
     * answer before any connection record exists.
     */
    public function connectionsToCoreBanking(): bool
    {
        return DB::table('tp_connections')
            ->where('organization_id', $this->organization_id)
            ->where('engagement_id', $this->getKey())
            ->whereIn('status', ['requested', 'active'])
            ->whereIn('type', ['direct_db', 'leased_line', 'api'])
            ->exists();
    }

    /** Whether any live access grant is privileged or administrative — KO-PRIV. */
    public function hasPrivilegedAccessGrant(): bool
    {
        return DB::table('tp_access_grants')
            ->where('organization_id', $this->organization_id)
            ->where('engagement_id', $this->getKey())
            ->where('status', 'active')
            ->whereIn('access_level', ['privileged', 'admin'])
            ->exists();
    }

    /**
     * Whether the residual score may be relied on to close a review or support
     * a board assertion — TRD §7.6. Below the threshold the UI says so rather
     * than showing a confident number over stale inputs.
     */
    public function hasReliableScore(): bool
    {
        $threshold = (float) config('tprm.scoring.data_confidence.assertion_threshold');

        return $this->data_confidence !== null && (float) $this->data_confidence >= $threshold;
    }
}
