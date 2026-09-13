<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Tprm\Engagement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An application or platform a process depends on.
 *
 * A seam register (ADR 0001): Blueprint §4.2 expects the Enterprise
 * Architecture module to own this inventory and this product has no EA module.
 * `external_ref` and the morph map are where it is repointed when one arrives.
 * `tprm_engagement_id` is the reuse rule in a column — the vendor behind an
 * application comes from TPRM, never from a second vendor list here.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property ?string $vendor_name
 * @property string $hosting_model
 * @property ?string $hosting_location
 * @property ?int $primary_site_id
 * @property ?int $owner_id
 * @property ?int $tprm_engagement_id
 * @property ?string $external_ref
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Application extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_applications';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'vendor_name', 'hosting_model',
        'hosting_location', 'primary_site_id', 'owner_id', 'tprm_engagement_id', 'external_ref',
        'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'primary_site_id' => 'integer',
            'owner_id' => 'integer',
            'tprm_engagement_id' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Site, $this> */
    public function primarySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'primary_site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'tprm_engagement_id');
    }

    /** @return HasMany<DataSet, $this> */
    public function dataSets(): HasMany
    {
        return $this->hasMany(DataSet::class, 'primary_application_id');
    }
}
