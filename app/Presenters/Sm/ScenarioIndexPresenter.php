<?php

namespace App\Presenters\Sm;

use App\Models\Sm\Scenario;
use App\Models\User;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Facades\Route;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scenario index payload, minus the grid (spec 00 §2).
 *
 * `grid` is shaped by `GridPresenter` over `ScenariosGrid` and passed in by the
 * controller; everything here is the register-wide context around it.
 *
 * ── `totals` AND `reviewCounts` IGNORE SEARCH AND FILTERS ─────────────────────
 *
 * Spec 00 §2: "The subtitle describes the register, the grid describes the current view,
 * and the two numbers must be allowed to differ without looking like a bug." §3.1 labels
 * them so they cannot be confused. `grid.rows.meta.total` is the filtered count and comes
 * from `GridPresenter`, not from here.
 *
 * ── EVERY COUNT IS A COUNT OF A SET ───────────────────────────────────────────
 *
 * Standard §5 permits `?? 0` for a count, because zero is a true statement about an empty
 * set. A CLAUSE WHOSE COUNT IS ZERO IS OMITTED rather than printed as "0 in validation"
 * (spec 00 §3.1) — the subtitle is a work queue and "0 returned for rework" is noise — but
 * `{scenarios}` is always printed, including at `0`. That omission is the frontend's; this
 * presenter's job is to send true numbers and never a rate.
 *
 * ── THE REVIEW COUNTS ARE DAY COMPARISONS IN LAGOS ────────────────────────────
 *
 * Computed server-side against `KriWorkingCalendar::today()` as a `Y-m-d` string, never
 * `->isPast()` and never a bare `now()` against a DATE column (standard §14). The browser
 * never works out whether a date is past: `new Date().toISOString()` is yesterday for the
 * first hour of a Lagos day (spec 01 §4.1).
 */
class ScenarioIndexPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $organizationId = TenantContext::organizationId();
        $today = KriWorkingCalendar::today()->toDateString();

        return [
            'totals' => $this->totals($organizationId),
            'reviewCounts' => $this->reviewCounts($organizationId, $today),
            // `can.create` and `createUrl` are SEPARATE because a `scenario.create` holder
            // still needs the route to exist — the feature flag can be off for one tenant
            // and on for another. When `createUrl` is null the button does not render.
            'can' => ['create' => $user->can('scenario.create') && Route::has('scenario.create')],
            'createUrl' => ($user->can('scenario.create') && Route::has('scenario.create'))
                ? route('scenario.create')
                : null,
            // The same prop the workspace sends (spec 01 §5.1), so the copy lives in one
            // place on the server and a user reads one consistent statement of what this
            // release does and does not do. Spec 00 §3.1 permits a shorter variant here.
            'roadmapNote' => config('sm.roadmap_note_index'),
        ];
    }

    /**
     * Grouped counts by status over the tenant's whole register, ignoring search and
     * filters.
     *
     * ONE `GROUP BY`, not four counts: the register is the same set four times, and
     * `sm_scenarios_org_status_idx` (§A1.2) exists to serve exactly this. Plain `GROUP BY`
     * is inside the MariaDB 10.4 ∩ MySQL 8.0.46 intersection — no CTE, no window function.
     *
     * @return array{scenarios: int, signed_off: int, in_validation: int, returned: int}
     */
    private function totals(int $organizationId): array
    {
        $byStatus = Scenario::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            // A count of a set, so `?? 0` is a true statement about an empty register
            // (standard §5) and not a fabricated figure.
            'scenarios' => (int) $byStatus->sum(),
            'signed_off' => (int) ($byStatus['signed_off'] ?? 0),
            'in_validation' => (int) ($byStatus['in_validation'] ?? 0),
            'returned' => (int) ($byStatus['returned_for_rework'] ?? 0),
        ];
    }

    /**
     * Spec 00 §2's two register-wide review counts.
     *
     * `undated` EXCLUDES `draft` AND `archived`: a draft has no review cadence yet and an
     * archived scenario has none any more, and counting either would inflate a number whose
     * only job is to say "these approved scenarios will never come back for review".
     *
     * @return array{overdue: int, undated: int}
     */
    private function reviewCounts(int $organizationId, string $today): array
    {
        return [
            'overdue' => Scenario::query()
                ->where('organization_id', $organizationId)
                ->reviewOverdue($today)
                ->count(),
            'undated' => Scenario::query()
                ->where('organization_id', $organizationId)
                ->reviewUndated()
                ->count(),
        ];
    }
}
