<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ThirdPartyStatus;
use App\Models\Concerns\HasObjectIdentity;
use App\Models\Organization;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A third party — the legal entity.
 *
 * RISK IS NOT ASSESSED HERE. It is assessed on the engagement (TRD §5.1), and
 * `aggregate_residual` on this row is a roll-up across engagements maintained
 * by the scoring service, not a score in its own right. The distinction is the
 * design: the same vendor hosting core banking and printing stationery is one
 * entity and two very different risks, and a module that scores the entity
 * cannot say so.
 *
 * Corporate evidence — ISO certificates, SOC 2 reports, audited accounts,
 * insurance — hangs off this record and is INHERITED by every engagement but
 * SCORED per engagement, because a certificate covering the vendor's cloud
 * platform is strong evidence for a hosting engagement and weak evidence for a
 * call centre.
 */
class ThirdParty extends Model
{
    use BelongsToOrganization, HasObjectIdentity, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_third_parties';

    /** @var list<string> */
    public const ENTITY_TYPES = ['company', 'partnership', 'sole_proprietor', 'government', 'ngo', 'intra_group'];

    protected $fillable = [
        'organization_id', 'legal_name', 'trading_name', 'slug', 'registration_number', 'tax_id', 'lei',
        'entity_type', 'ownership_type', 'country_of_incorporation', 'country_of_hq', 'website',
        'year_established', 'employee_band', 'ultimate_parent_id', 'is_intra_group', 'status',
        'relationship_owner_id', 'oversight_owner_id', 'category_id', 'notes', 'logo_path', 'vendor_identity_id',
        'portal_enabled', 'data_confidence', 'aggregate_residual', 'screening_status',
        'last_screened_at', 'blacklisted_at', 'blacklist_reason', 'created_by', 'updated_by',
    ];

    /**
     * Declared here as well as in the migration, so a freshly created model
     * and its stored row agree. See the note on Engagement::$attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'prospect',
        'is_intra_group' => false,
        'portal_enabled' => false,
    ];

    protected $casts = [
        'status' => ThirdPartyStatus::class,
        'is_intra_group' => 'boolean',
        'portal_enabled' => 'boolean',
        'data_confidence' => 'decimal:2',
        'aggregate_residual' => 'decimal:2',
        'last_screened_at' => 'datetime',
        'blacklisted_at' => 'datetime',
        'year_established' => 'integer',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * The group parent. The concentration analyser groups by this rather than
     * by the entity: five engagements with five subsidiaries of one group is
     * not a diversified portfolio, and the HHI in TRD §7.8 says so only if it
     * is computed over the group.
     *
     * @return BelongsTo<self, $this>
     */
    public function ultimateParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ultimate_parent_id');
    }

    /** @return HasMany<self, $this> */
    public function subsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'ultimate_parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function relationshipOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function oversightOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oversight_owner_id');
    }

    /**
     * The global company record this supplier row represents, if the vendor
     * has claimed one — FR-PRT-04.
     *
     * Null for most rows and that is normal: a vendor with no portal account
     * has no identity and needs none. Nothing sets this automatically; the
     * vendor claims it, because a wrong link shows one bank the posture
     * another bank's vendor declared.
     *
     * @return BelongsTo<VendorIdentity, $this>
     */
    public function vendorIdentity(): BelongsTo
    {
        return $this->belongsTo(VendorIdentity::class, 'vendor_identity_id');
    }

    /** @return HasMany<Engagement, $this> */
    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class, 'third_party_id');
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'third_party_id');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'third_party_id');
    }

    /**
     * Whether any screening check on this entity or one of its owners carries
     * a CONFIRMED true match — the KO-SANCTION condition and the AC-08
     * trigger.
     *
     * Reads `tp_screening_checks` and `tp_screening_matches` directly; the
     * screening models belong to Phase 6. Scoped to the organisation
     * explicitly, because the query builder carries no global scope.
     *
     * A `possible` match is deliberately NOT a true match. The distinction is
     * an analyst's decision with a rationale behind it, and treating "we could
     * not establish it" as "confirmed" would suspend every engagement with the
     * vendor on an unresolved alert.
     */
    public function hasConfirmedSanctionsMatch(): bool
    {
        return DB::table('tp_screening_matches')
            ->join('tp_screening_checks', 'tp_screening_matches.check_id', '=', 'tp_screening_checks.id')
            ->where('tp_screening_matches.organization_id', $this->organization_id)
            ->where('tp_screening_matches.decision', 'true_match')
            ->where(function ($query) {
                $query->where(function ($entity) {
                    $entity->where('tp_screening_checks.subject_type', 'third_party')
                        ->where('tp_screening_checks.subject_id', $this->getKey());
                })->orWhere(function ($owner) {
                    $owner->where('tp_screening_checks.subject_type', 'ownership')
                        ->whereIn('tp_screening_checks.subject_id', function ($sub) {
                            $sub->select('id')->from('tp_ownership')
                                ->where('third_party_id', $this->getKey())
                                ->whereNull('deleted_at');
                        });
                });
            })
            ->exists();
    }

    /**
     * Shareholders, directors and beneficial owners.
     *
     * Every row flagged director or UBO is screened in its own right, not only
     * the entity — CBN AML/CFT Regulations Reg. 29.
     *
     * @return HasMany<Ownership, $this>
     */
    public function ownership(): HasMany
    {
        return $this->hasMany(Ownership::class, 'third_party_id');
    }
}
