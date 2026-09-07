<?php

namespace App\Services\LossEvents;

use App\Models\LossEvent;
use App\Models\LossEventRca;
use App\Models\NearMiss;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The figures behind the loss-event dashboard and the RCA overview
 * (migration Phase 4.3).
 *
 * Lifted out of LossEventController, which computed both inline. Money is
 * stored in KOBO throughout `loss_events` and every figure here converts to
 * naira once, at the edge — a screen that divides by 100 in three places is a
 * screen where one of them will eventually be forgotten.
 *
 * Pinned by tests/Feature/Characterisation/LossEventDashboardFiguresTest, which
 * could not have been written before this class existed: the monthly trend used
 * `MONTH(date_of_loss)`, which is MySQL-only, so the dashboard threw on the
 * SQLite the suite runs on and had never been covered by a test at all.
 */
class LossEventDashboardService
{
    /**
     * Statuses that count as still open. Mirrors
     * LossEventController::OPEN_STATUSES, which is where they were.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = ['REPORTED', 'UNDER_INVESTIGATION', 'PENDING_APPROVAL'];

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * The eight KPI tiles, all for the current year.
     *
     * @return array<string, int|float>
     */
    public function kpis(): array
    {
        $year = $this->scopedToYear();

        return [
            'totalEvents' => $year()->count(),
            'totalGrossLoss' => $this->naira($year()->sum('gross_loss_amount_kobo')),
            'recoveredAmount' => $this->naira(
                $year()->sum(DB::raw('insurance_recovery_kobo + other_recovery_kobo'))
            ),
            'netLossYtd' => $this->naira(
                $year()->sum(DB::raw('gross_loss_amount_kobo - insurance_recovery_kobo - other_recovery_kobo'))
            ),

            // These four are not year-bounded, and were not before: an
            // outstanding CBN notification from last December is still
            // outstanding today.
            'pendingCbnNotifications' => $this->scoped()
                ->where('is_regulatory_reportable', true)
                ->whereIn('current_status', self::OPEN_STATUSES)
                ->count(),
            'pendingNfiuFilings' => $this->scoped()
                ->where('nfiu_reportable', true)
                ->where(fn (Builder $q) => $q->whereNull('nfiu_report_filed')->orWhere('nfiu_report_filed', false))
                ->count(),
            'openInvestigations' => $this->scoped()->whereIn('current_status', self::OPEN_STATUSES)->count(),
            'nearMisses' => NearMiss::where('organization_id', $this->orgId())
                ->whereYear('date_occurred', now()->year)
                ->count(),
        ];
    }

    /**
     * Events and gross loss per month of the current year, zero-filled to
     * twelve so the axis does not rescale between tenants.
     *
     * @return list<array{month: string, count: int, loss: float}>
     */
    public function monthlyTrend(): array
    {
        $rows = DB::table('loss_events')
            ->where('organization_id', $this->orgId())
            ->whereNull('deleted_at')
            ->whereYear('date_of_loss', now()->year)
            ->selectRaw($this->monthNumber('date_of_loss').' as m, COUNT(*) as c, SUM(gross_loss_amount_kobo) as k')
            ->groupBy('m')
            ->get()
            ->keyBy('m');

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return array_map(function (string $label, int $index) use ($rows) {
            $row = $rows->get($index + 1);

            return [
                'month' => $label,
                'count' => $row ? (int) $row->c : 0,
                'loss' => $row ? $this->naira($row->k) : 0.0,
            ];
        }, $labels, array_keys($labels));
    }

    /**
     * Gross loss per Basel level-1 category, worst first.
     *
     * @return list<array{category: string, count: int, loss: float}>
     */
    public function baselCategories(): array
    {
        // The query builder rather than the model: a grouped aggregate
        // returns aliases, and hydrating them as LossEvent rows would claim
        // they are events.
        return DB::table('loss_events')
            ->where('organization_id', $this->orgId())
            ->whereNull('deleted_at')
            ->whereYear('date_of_loss', now()->year)
            ->selectRaw('basel_l1_category, COUNT(*) as events, SUM(gross_loss_amount_kobo) as total_kobo')
            ->groupBy('basel_l1_category')
            ->orderByDesc('total_kobo')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->basel_l1_category ?: 'Unclassified',
                'count' => (int) $row->events,
                'loss' => $this->naira($row->total_kobo),
            ])
            ->all();
    }

    /**
     * The ten most recent events, whatever year they fall in.
     *
     * @return list<array<string, mixed>>
     */
    public function recentEvents(int $limit = 10): array
    {
        return $this->scoped()
            ->with('businessUnit')
            ->orderByDesc('date_of_loss')
            ->limit($limit)
            ->get()
            ->map(fn (LossEvent $event) => [
                'id' => $event->id,
                'reference' => $event->event_reference,
                'title' => $event->title,
                'category' => $event->basel_l1_category,
                'severity' => $event->event_severity,
                'status' => $event->current_status,
                'businessUnit' => $event->businessUnit?->name,
                'dateOfLoss' => $event->date_of_loss?->format('d M Y'),
                'grossLoss' => $this->naira($event->gross_loss_amount_kobo),
                'url' => route('risk.loss-events.show', $event),
            ])
            ->all();
    }

    /**
     * Reportable events still open, soonest first.
     *
     * THE REFERENCE IS THE REAL ONE NOW. The Blade version read
     * `$e->reference ?? ('LE-'.$e->id)`, and `reference` is neither a column on
     * `loss_events` (it is `event_reference`) nor an accessor — so the fallback
     * fired every time and this panel showed an id-derived string that matches
     * nothing a user can search for.
     *
     * The three-day clock is the CBN ORMS notification window and is carried
     * across unchanged.
     *
     * @return list<array<string, mixed>>
     */
    public function regulatoryAlerts(int $limit = 5): array
    {
        return $this->scoped()
            ->where('is_regulatory_reportable', true)
            ->whereIn('current_status', self::OPEN_STATUSES)
            ->orderBy('date_of_loss')
            ->limit($limit)
            ->get()
            ->map(function (LossEvent $event) {
                $deadline = $event->date_of_loss?->copy()->addDays(3);

                return [
                    'id' => $event->id,
                    'type' => 'CBN ORMS notification',
                    'reference' => $event->event_reference,
                    'deadline' => $deadline?->format('d M Y'),
                    'isOverdue' => $deadline?->isPast() ?? false,
                    'url' => route('risk.loss-events.show', $event),
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  RCA overview */
    /* ------------------------------------------------------------------ */

    /**
     * The RCA screen's four counts.
     *
     * `pending` counts LOSS EVENTS WITH NO RCA AT ALL, not RCAs in a pending
     * state — which is why the three status counts do not sum to `total`. That
     * asymmetry is carried across: an event nobody has analysed is the thing
     * the screen exists to surface.
     *
     * @return array<string, int>
     */
    public function rcaCounts(): array
    {
        $rcas = fn () => LossEventRca::where('organization_id', $this->orgId());

        return [
            'total' => $rcas()->count(),
            'completed' => $rcas()->whereIn('rca_status', ['APPROVED', 'COMPLETED'])->count(),
            'inProgress' => $rcas()->whereIn('rca_status', ['NOT_STARTED', 'IN_PROGRESS'])->count(),
            'pending' => $this->scoped()->whereDoesntHave('rca')->count(),
        ];
    }

    /**
     * Root causes per category.
     *
     * The five labels are fixed and "Systems" maps to the stored `system` —
     * carried across, including that `Governance` has no stored counterpart and
     * so is always zero.
     *
     * @return list<array{label: string, value: int}>
     */
    public function rcaCategories(): array
    {
        $counts = LossEventRca::where('organization_id', $this->orgId())
            ->selectRaw('root_cause_category, COUNT(*) as c')
            ->groupBy('root_cause_category')
            ->pluck('c', 'root_cause_category');

        return array_map(fn (string $label) => [
            'label' => $label,
            'value' => (int) ($counts[strtolower($label === 'Systems' ? 'system' : $label)] ?? 0),
        ], ['People', 'Process', 'Systems', 'External', 'Governance']);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function orgId(): int
    {
        return TenantContext::organizationId();
    }

    /** @return Builder<LossEvent> */
    private function scoped(): Builder
    {
        return LossEvent::query()->where('organization_id', $this->orgId());
    }

    /** @return callable(): Builder<LossEvent> */
    private function scopedToYear(): callable
    {
        return fn () => $this->scoped()->whereYear('date_of_loss', now()->year);
    }

    /** Kobo to naira, in one place. */
    private function naira(float|int|string|null $kobo): float
    {
        return round(((float) $kobo) / 100, 2);
    }

    /**
     * The month number of a date column.
     *
     * `MONTH()` is MySQL-only. The Blade dashboard used it raw, so this whole
     * screen threw on any other driver — which is why it had no test.
     */
    private function monthNumber(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "EXTRACT(MONTH FROM {$column})",
            default => "MONTH({$column})",
        };
    }
}
