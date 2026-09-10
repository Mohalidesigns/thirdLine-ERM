<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A person who can be reached in an emergency — the substrate the call tree
 * and EMNS both stand on, which is why AD/Entra moved to Week 2
 * (Orchestration §1).
 *
 * NEVER QUERY `users` FOR A CHANNEL. A user has a name and a login; a contact
 * has a mobile number, a WhatsApp handle, a language, a consent state and a
 * verification date. `user_id` is nullable because a guard, a cleaner and a
 * contractor all need to be reachable in an evacuation and none has a login.
 *
 * NDPA: `consent_status` covers the personal-phone channels only. A corporate
 * email needs no consent, and conflating the two would let a withdrawal
 * silently remove somebody from a life-safety roll-call.
 *
 * `ad_synced_at` records a READ. Nothing is ever written back to Active
 * Directory (standing rule 3) and there is no column that could record one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $user_id
 * @property \App\Enums\Bcms\ContactSource $source
 * @property string $full_name
 * @property ?string $employee_id
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property ?string $title
 * @property ?int $manager_user_id
 * @property ?string $email
 * @property ?string $mobile_primary
 * @property ?string $mobile_secondary
 * @property ?string $whatsapp
 * @property ?string $teams_id
 * @property ?string $slack_id
 * @property ?string $push_token
 * @property array<array-key, mixed> $next_of_kin
 * @property string $preferred_language
 * @property array<array-key, mixed> $channel_preferences
 * @property array<array-key, mixed> $geo_last_known
 * @property ?string $latitude
 * @property ?string $longitude
 * @property string $consent_status
 * @property ?\Illuminate\Support\Carbon $consent_captured_at
 * @property ?\Illuminate\Support\Carbon $consent_withdrawn_at
 * @property string $verification_status
 * @property ?\Illuminate\Support\Carbon $last_verified_at
 * @property int $consecutive_failures
 * @property bool $is_active
 * @property ?\Illuminate\Support\Carbon $ad_synced_at
 * @property ?string $ad_object_guid
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Contact extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_contacts';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'user_id', 'source', 'full_name', 'employee_id', 'business_unit_id',
        'site_id', 'title', 'manager_user_id', 'email', 'mobile_primary', 'mobile_secondary',
        'whatsapp', 'teams_id', 'slack_id', 'push_token', 'next_of_kin', 'preferred_language',
        'channel_preferences', 'geo_last_known', 'latitude', 'longitude', 'consent_status',
        'consent_captured_at', 'consent_withdrawn_at', 'verification_status', 'last_verified_at',
        'consecutive_failures', 'is_active', 'ad_synced_at', 'ad_object_guid', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'next_of_kin' => 'array',
            'channel_preferences' => 'array',
            'geo_last_known' => 'array',
            'organization_id' => 'integer',
            'user_id' => 'integer',
            'business_unit_id' => 'integer',
            'site_id' => 'integer',
            'manager_user_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'consent_captured_at' => 'datetime',
            'consent_withdrawn_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'is_active' => 'boolean',
            'ad_synced_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'source' => ContactSource::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
