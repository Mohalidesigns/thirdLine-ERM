<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model backed by a table carrying organization_id.
 *
 * Gives the model three things:
 *   1. a global scope filtering reads to the current tenant;
 *   2. a creating() hook stamping organization_id so callers never have to;
 *   3. bypassTenancy(), the single audited escape hatch for system work.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $column = $model->getOrganizationIdColumn();

            if ($model->getAttribute($column) !== null) {
                return;
            }

            if (TenantContext::isBypassed()) {
                return;
            }

            $organizationId = TenantContext::organizationIdOrNull();

            if ($organizationId !== null) {
                $model->setAttribute($column, $organizationId);
            }
        });
    }

    /**
     * Query this model across every tenant.
     *
     * Reserved for system jobs and reports that legitimately span
     * organizations. Each call is logged by TenantContext.
     */
    public static function bypassTenancy(string $reason = 'unspecified'): Builder
    {
        return TenantContext::bypass(
            fn () => static::query()->withoutGlobalScope(OrganizationScope::class),
            $reason
        );
    }

    public function getOrganizationIdColumn(): string
    {
        return 'organization_id';
    }

    public function getQualifiedOrganizationIdColumn(): string
    {
        return $this->getTable().'.'.$this->getOrganizationIdColumn();
    }

    /**
     * Whether rows with a NULL organization_id are visible to every tenant.
     *
     * Models holding shared system content (question library, notification
     * templates) override the $tenantIncludesGlobal property to true.
     */
    public function tenantIncludesGlobalRecords(): bool
    {
        return property_exists($this, 'tenantIncludesGlobal') && $this->tenantIncludesGlobal === true;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}
