<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A shareholder, director, officer or ultimate beneficial owner.
 *
 * Every row whose `relationship` is director or ubo is screened in its own
 * right — CBN AML/CFT Regulations Reg. 29 — and any change to this table
 * re-triggers screening. A confirmed true match on one of these rows suspends
 * every engagement with the third party and forces its residual to 100
 * (AC-08); the entity being clean is not enough when its owner is not.
 */
class Ownership extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tp_ownership';

    /** @var list<string> */
    public const RELATIONSHIPS = ['shareholder', 'director', 'ubo', 'officer'];

    /** The relationships Reg. 29 requires to be screened individually. */
    public const SCREENABLE_RELATIONSHIPS = ['director', 'ubo'];

    protected $fillable = [
        'organization_id', 'third_party_id', 'holder_name', 'holder_type', 'relationship',
        'percentage', 'nationality', 'date_of_birth', 'is_pep', 'pep_category',
        'source', 'verified_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'date_of_birth' => 'date',
        'is_pep' => 'boolean',
        'verified_at' => 'datetime',
    ];

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

    public function requiresIndividualScreening(): bool
    {
        return in_array($this->relationship, self::SCREENABLE_RELATIONSHIPS, true);
    }
}
