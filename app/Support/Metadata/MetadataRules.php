<?php

namespace App\Support\Metadata;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rules\Exists;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Tenant-bound existence rules for the metadata registry (migration Phase 6.3).
 *
 * The four builders carried seven `exists:object_types,id` and
 * `exists:object_lifecycles,id` rules between them — the bare form this
 * programme has been replacing since Phase 3 — on models that are **not**
 * global. ObjectType, ObjectLifecycle and ObjectRelationshipType all use
 * BelongsToOrganization with `$tenantIncludesGlobal = true`, which means a
 * tenant sees the seeded system rows (organization_id NULL) and its own, and
 * nobody else's. The validator saw all of them.
 *
 * So a tenant could point a parent type, an allowed child, a default
 * lifecycle, a link field's target, or a relationship's endpoints at another
 * institution's custom type — putting that institution's names on their
 * screens and its ids in their graph.
 *
 * `visible()` is the same predicate the global scope applies, written once.
 * It also excludes soft-deleted rows, which `exists:` never did: ObjectType
 * soft-deletes, so a type that had been deleted stayed a valid target.
 */
class MetadataRules
{
    /**
     * A row of $table this tenant may refer to: their own, or a system row.
     */
    public static function visible(string $table, bool $softDeletes = true): Exists
    {
        $organizationId = TenantContext::organizationId();

        return (new Exists($table, 'id'))->where(function (Builder $query) use ($organizationId, $softDeletes) {
            $query->where(function (Builder $query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            });

            if ($softDeletes) {
                $query->whereNull('deleted_at');
            }
        });
    }

    /** Object types: tenant-owned or seeded, and not soft-deleted. */
    public static function objectType(): Exists
    {
        return self::visible('object_types');
    }

    /** Lifecycles: no soft deletes on this table. */
    public static function objectLifecycle(): Exists
    {
        return self::visible('object_lifecycles', softDeletes: false);
    }

    /**
     * Workflow definitions, referenced by a lifecycle state.
     *
     * Stricter than the metadata tables: WorkflowDefinition uses
     * BelongsToOrganization WITHOUT `$tenantIncludesGlobal`, so there is no
     * such thing as a shared definition and a NULL organization_id would be a
     * row no tenant should see. LifecycleBuilder never validated this field at
     * all — `$state['required_workflow_id'] ?: null` went straight into the
     * states blob — so a lifecycle could demand another institution's workflow
     * before a record could move.
     */
    public static function workflowDefinition(): Exists
    {
        $organizationId = TenantContext::organizationId();

        return (new Exists('workflow_definitions', 'id'))
            ->where(fn (Builder $query) => $query
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at'));
    }
}
