<?php

namespace App\Services\Widgets;

use App\Models\Dashboard;
use App\Models\DashboardUserPref;
use App\Models\GraphObject;
use App\Models\User;

/**
 * WP-08 TASK 4 — which dashboard does this page render?
 *
 * Resolution order, most specific first:
 *   1. a published dashboard for the object's exact type, composed for one of
 *      the viewer's roles;
 *   2. a published dashboard for the type, composed for every role;
 *   3. the type-agnostic default (object_type_id NULL), same role ordering.
 *
 * Role-specific beats role-generic because that is what "composed for the
 * board" means: a board member landing on the enterprise node gets the board
 * dashboard, not the analyst one, even though both are published for the
 * Enterprise type.
 */
class DashboardResolver
{
    public function resolveFor(?GraphObject $object, User $user): ?Dashboard
    {
        $typeId = $object?->object_type_id;

        return $this->pick($typeId, $user)
            ?? ($typeId === null ? null : $this->pick(null, $user));
    }

    /** The viewer's saved layout for a dashboard, already staleness-checked. */
    public function layoutFor(Dashboard $dashboard, User $user): array
    {
        $pref = DashboardUserPref::query()
            ->where('user_id', $user->id)
            ->where('dashboard_id', $dashboard->id)
            ->first();

        $override = $pref?->layoutFor($dashboard);

        if ($override === null) {
            return $dashboard->tabList();
        }

        // The override replaces placements per tab but can neither add nor
        // remove widgets — hiding a published widget is the builder's call.
        return collect($dashboard->tabList())
            ->map(function (array $tab) use ($override) {
                $placements = $override[$tab['code']] ?? null;

                if (! is_array($placements)) {
                    return $tab;
                }

                $published = collect($tab['layout'])->keyBy(fn (array $p) => (int) $p['widget_id']);

                $tab['layout'] = collect($placements)
                    ->filter(fn ($p) => is_array($p) && $published->has((int) ($p['widget_id'] ?? 0)))
                    ->map(function (array $p) use ($published) {
                        $base = $published->get((int) $p['widget_id']);

                        return array_merge($base, [
                            'x' => (int) ($p['x'] ?? $base['x'] ?? 0),
                            'y' => (int) ($p['y'] ?? $base['y'] ?? 0),
                            'w' => (int) ($p['w'] ?? $base['w'] ?? 4),
                            'h' => (int) ($p['h'] ?? $base['h'] ?? 3),
                        ]);
                    })
                    ->values()
                    ->all();

                // Widgets the override forgot keep their published placement.
                $seen = collect($tab['layout'])->pluck('widget_id')->map(fn ($id) => (int) $id)->flip();
                foreach ($published as $id => $placement) {
                    if (! $seen->has($id)) {
                        $tab['layout'][] = $placement;
                    }
                }

                return $tab;
            })
            ->all();
    }

    private function pick(?int $typeId, User $user): ?Dashboard
    {
        $base = fn () => Dashboard::query()
            ->published()
            ->when(
                $typeId === null,
                fn ($q) => $q->whereNull('object_type_id'),
                fn ($q) => $q->where('object_type_id', $typeId),
            );

        $roleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($roleIds as $roleId) {
            $match = $base()->whereJsonContains('role_ids', $roleId)->orderByDesc('is_default_for_role')->first();

            if ($match !== null) {
                return $match;
            }
        }

        return $base()->whereNull('role_ids')->first();
    }
}
