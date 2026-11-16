<?php

namespace App\Models\Concerns;

use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Human-readable business keys per the ThirdLine standard §9.2:
 * PREFIX-[YEAR-]NNN, organization-scoped, rendered in font-mono in the UI.
 */
trait GeneratesReference
{
    public static function nextReference(string $prefix, bool $withYear = true, string $column = 'incident_ref'): string
    {
        $year = now()->year;
        $stem = $withYear ? "{$prefix}-{$year}-" : "{$prefix}-";

        $orgId = TenantContext::organizationId() ?? auth()->user()?->organization_id;

        $last = static::withoutGlobalScopes()
            ->where($column, 'like', $stem.'%')
            ->when($orgId, fn ($q, $o) => $q->where('organization_id', $o))
            ->orderByDesc('id')
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
