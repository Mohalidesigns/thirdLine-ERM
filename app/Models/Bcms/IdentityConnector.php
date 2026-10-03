<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\AutoApplyPolicy;
use App\Enums\Bcms\IdentityProvider;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One tenant's configured directory connector — ADR 0018 §2.2.
 *
 * ORGANISATION-LEVEL, NOT SCOPED TO A BUSINESS UNIT (ADR 0018 §8): one per
 * tenant, holds no business unit and should not — it is not
 * `ScopedToOrgHierarchy`, and its routes stand on `permission:` middleware
 * alone until ADR 0017's remediation lands.
 *
 * `client_secret` IS NEVER SENT TO A SCREEN, NOT EVEN REDACTED — `$hidden`
 * covers it in addition to the `encrypted` cast, because a `toArray()` a
 * future screen calls without going through the presenter must not leak it
 * either.
 *
 * NO STORED STATUS COLUMN. Health and last-success are derived from
 * `bcms_identity_sync_runs` by `App\Services\Bcms\Identity\ConnectorHealth` —
 * the same argument ADR 0013 made for call-tree staleness: a stored health
 * column is only as true as its last write.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property \App\Enums\Bcms\IdentityProvider $provider
 * @property string $name
 * @property string $directory_tenant_id
 * @property string $client_id
 * @property ?string $client_secret
 * @property string $token_base_url
 * @property string $graph_base_url
 * @property ?string $directory_filter
 * @property ?array<string, string> $attribute_map
 * @property string $sync_schedule
 * @property \App\Enums\Bcms\AutoApplyPolicy $auto_apply_policy
 * @property ?string $delta_link
 * @property ?\Illuminate\Support\Carbon $credential_expires_on
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class IdentityConnector extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory;

    protected $table = 'bcms_identity_connectors';

    /** @var list<string> */
    protected $hidden = ['client_secret'];

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'provider', 'name', 'directory_tenant_id', 'client_id', 'client_secret',
        'token_base_url', 'graph_base_url', 'directory_filter', 'attribute_map', 'sync_schedule',
        'auto_apply_policy', 'delta_link', 'credential_expires_on', 'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'provider' => IdentityProvider::class,
            'client_secret' => 'encrypted',
            'attribute_map' => 'array',
            'auto_apply_policy' => AutoApplyPolicy::class,
            'credential_expires_on' => 'date',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * Secrets are excluded from the audit trail too, not only from the
     * screen — a before/after of `client_secret` would put the ciphertext
     * envelope in a table meant for evidence, which is a different kind of
     * leak than the plaintext one but still not nothing.
     *
     * `BcmsAuditable::auditExcluded()` IS A TRAIT METHOD, NOT A PARENT CLASS
     * ONE — there is no `parent::auditExcluded()` to call from a model that
     * uses the trait; overriding it here replaces it entirely, so the base
     * exclusions are repeated rather than inherited.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return ['updated_at', 'push_token', 'raw_response', 'client_secret', 'delta_link'];
    }

    /** @return HasMany<IdentitySyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(IdentitySyncRun::class)->latest('started_at');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The map a run should use — the tenant's saved one, or the Blueprint §8.2 default. */
    public function effectiveAttributeMap(): array
    {
        return $this->attribute_map ?: \App\Support\Bcms\DirectoryAttributeMap::defaults();
    }
}
