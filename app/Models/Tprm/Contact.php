<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A named person at the third party.
 *
 * `portal_user_id` is a plain integer, not a relationship to `users`. A portal
 * user lives in `tp_portal_users` under its own guard with no relationship to
 * the internal user model, which is the portal's whole security argument — see
 * the part 9 migration.
 */
class Contact extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tp_contacts';

    /** @var list<string> */
    public const ROLE_TYPES = ['relationship', 'security', 'dpo', 'incident', 'billing', 'executive'];

    protected $fillable = [
        'organization_id', 'third_party_id', 'name', 'role_type', 'email', 'phone',
        'is_primary_portal_contact', 'portal_user_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_primary_portal_contact' => 'boolean',
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
}
