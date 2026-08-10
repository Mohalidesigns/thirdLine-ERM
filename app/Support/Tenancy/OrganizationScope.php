<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-scoped model to the organization held in
 * TenantContext.
 *
 * When no tenant is resolved the scope is inert. That is deliberate: console
 * commands, migrations and seeders run untenanted, and HTTP requests can never
 * reach a controller untenanted because ResolveTenant aborts first.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isBypassed()) {
            return;
        }

        $organizationId = TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return;
        }

        $column = $model->getQualifiedOrganizationIdColumn();

        // Models that also hold system-wide rows (a shared question library,
        // default notification templates) opt in to seeing NULL-tenant rows
        // alongside their own.
        if ($model->tenantIncludesGlobalRecords()) {
            $builder->where(function (Builder $query) use ($column, $organizationId) {
                $query->where($column, $organizationId)->orWhereNull($column);
            });

            return;
        }

        $builder->where($column, $organizationId);
    }
}
