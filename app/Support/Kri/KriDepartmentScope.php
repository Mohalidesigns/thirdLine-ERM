<?php

namespace App\Support\Kri;

use App\Enums\KriContactRole;
use App\Models\KriDepartmentContact;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which departments a person may see and act on in the KRI cycle (ADR 0026
 * section 10).
 *
 * NULL MEANS "EVERY UNIT", AND ONLY A PERMISSION OR THE ABSENCE OF A USER
 * PRODUCES IT. An empty list means nothing - never everything. The two are the
 * same query shape with opposite meanings, and conflating them fails open for
 * exactly the accounts nobody configured.
 *
 * Seeing is wider than acting: championUnitIdsFor() is EXACT units where the
 * person is a risk champion, never expanded to descendants - a champion of
 * Retail is not a champion of its branches.
 */
class KriDepartmentScope
{
    public const ALL_DEPARTMENTS = 'kri.all_departments';

    public function __construct(private readonly RcsaScope $tree) {}

    /** @return list<int>|null */
    public function unitIdsFor(?User $user): ?array
    {
        // Console, scheduler, queue: bounded by TenantContext already.
        if ($user === null || $user->can(self::ALL_DEPARTMENTS)) {
            return null;
        }

        $organizationId = (int) $user->organization_id;
        $ids = [];

        $assignments = DB::table('business_unit_user')
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->pluck('includes_descendants', 'business_unit_id');

        foreach ($assignments as $unitId => $includesDescendants) {
            if ((bool) $includesDescendants) {
                foreach ($this->tree->subtreeOf((int) $unitId, $organizationId) as $id) {
                    $ids[(int) $id] = true;
                }
            } else {
                $ids[(int) $unitId] = true;
            }
        }

        foreach ($this->championUnitIdsFor($user) as $id) {
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /** @return list<int> */
    public function championUnitIdsFor(User $user): array
    {
        return KriDepartmentContact::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('role', KriContactRole::RiskChampion->value)
            ->pluck('business_unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function reaches(?User $user, int $unitId): bool
    {
        $units = $this->unitIdsFor($user);

        return $units === null || in_array($unitId, $units, true);
    }

    /** Whether the user holds a contact row of ANY role for exactly this unit. */
    public function isContactOf(User $user, int $unitId): bool
    {
        return KriDepartmentContact::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('business_unit_id', $unitId)
            ->exists();
    }

    public function isChampionOf(User $user, int $unitId): bool
    {
        return in_array($unitId, $this->championUnitIdsFor($user), true);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?User $user, string $column = 'business_unit_id'): Builder
    {
        $units = $this->unitIdsFor($user);

        return $units === null ? $query : $query->whereIn($column, $units);
    }

    /** A sentence for a screen that came back empty because of scope, or null. */
    public function describe(?User $user): ?string
    {
        $units = $this->unitIdsFor($user);

        if ($units === []) {
            return 'You are not assigned to any business unit and are not a risk champion for one, so there are no departments to show you. Ask an administrator to assign you.';
        }

        return null;
    }
}
