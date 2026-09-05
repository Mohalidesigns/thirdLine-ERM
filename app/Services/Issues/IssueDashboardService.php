<?php

namespace App\Services\Issues;

use App\Models\Issue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The issues dashboard's figures (migration Phase 4.4).
 *
 * Lifted out of IssueController::dashboard(), which ran TWELVE separate COUNT
 * queries plus four ageing counts inline and then derived a few more from them.
 * They are grouped queries here — one for the statuses, one for the priorities —
 * so the screen costs three round trips rather than sixteen.
 *
 * Pinned by tests/Feature/Characterisation/IssueDashboardFiguresTest, which
 * could not have been written before the port: `avgDaysToClose` used
 * `DATEDIFF()`, which is MySQL-only, so the dashboard threw on the SQLite the
 * suite runs on and had never been covered.
 */
class IssueDashboardService
{
    /**
     * Statuses that take an issue out of the working population.
     *
     * @var list<string>
     */
    public const SETTLED_STATUSES = ['CLOSED', 'CANCELLED'];

    /**
     * The ageing bands, as [label, from, to] in days open. `to` null means
     * open-ended.
     *
     * BOUNDARIES ARE HALF-OPEN, and that is a correction. The Blade version
     * counted `>= now()-30` for the first band and
     * `whereBetween(now()-60, now()-30)` for the second, and whereBetween is
     * inclusive at BOTH ends — so an issue created exactly 30 days ago was
     * counted in both bands and the four buckets could sum to more than the
     * population. Each issue now falls in exactly one.
     *
     * @var list<array{0: string, 1: int, 2: int|null}>
     */
    public const AGE_BANDS = [
        ['0-30 days', 0, 30],
        ['31-60 days', 31, 60],
        ['61-90 days', 61, 90],
        ['90+ days', 91, null],
    ];

    /**
     * Counts per status, plus the derived headline figures.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $byStatus = $this->scoped()
            ->selectRaw('issue_status, COUNT(*) as total')
            ->groupBy('issue_status')
            ->pluck('total', 'issue_status');

        $status = fn (string $key) => (int) ($byStatus[$key] ?? 0);

        $byPriority = $this->open()
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $priority = fn (string $key) => (int) ($byPriority[$key] ?? 0);

        return [
            'total' => (int) $byStatus->sum(),
            'open' => $status('OPEN'),
            'inProgress' => $status('IN_PROGRESS'),
            'overdue' => $status('OVERDUE'),
            'pendingClosure' => $status('PENDING_CLOSURE'),
            'closed' => $status('CLOSED'),

            // The priority counts are of OPEN issues only — a closed critical
            // finding is not outstanding work.
            'critical' => $priority('critical'),
            'high' => $priority('high'),
            'medium' => $priority('medium'),
            'low' => $priority('low'),

            // What the header tiles show.
            'openIssues' => $status('OPEN') + $status('IN_PROGRESS'),
            'cbnFindings' => $this->open()->where('issue_source', 'cbn_examination')->count(),
            'avgDaysToClose' => $this->averageDaysToClose(),
        ];
    }

    /**
     * Mean days from raised to closed, over closed issues.
     *
     * `DATEDIFF()` is MySQL-only. It is computed in PHP now, over the two
     * columns, which is portable and costs one query either way — and is what
     * lets this figure be tested at all.
     */
    public function averageDaysToClose(): int
    {
        $rows = $this->scoped()
            ->where('issue_status', 'CLOSED')
            ->whereNotNull('closed_at')
            ->get(['created_at', 'closed_at']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $days = $rows->map(
            fn (Issue $issue) => $issue->created_at->startOfDay()->diffInDays($issue->closed_at->startOfDay())
        );

        return (int) round($days->avg());
    }

    /**
     * Open issues per ageing band.
     *
     * @return list<array{label: string, value: int}>
     */
    public function ageing(): array
    {
        return array_map(function (array $band) {
            [$label, $from, $to] = $band;

            $query = $this->open()->where('created_at', '<=', now()->subDays($from));

            if ($to !== null) {
                $query->where('created_at', '>', now()->subDays($to + 1));
            }

            return ['label' => $label, 'value' => $query->count()];
        }, self::AGE_BANDS);
    }

    /**
     * The ten oldest overdue issues, soonest due first.
     *
     * @return list<array<string, mixed>>
     */
    public function overdueIssues(int $limit = 10): array
    {
        return $this->scoped()
            ->where('issue_status', 'OVERDUE')
            ->with(['issueOwner', 'businessUnit'])
            ->orderBy('remediation_due_date')
            ->limit($limit)
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'reference' => $issue->issue_reference,
                'title' => $issue->title,
                'priority' => $issue->priority,
                'owner' => $issue->issueOwner?->name,
                'businessUnit' => $issue->businessUnit?->name,
                'dueDate' => $issue->remediation_due_date?->format('d M Y'),
                'daysOverdue' => $issue->remediation_due_date
                    ? (int) floor(now()->startOfDay()->diffInDays($issue->remediation_due_date->startOfDay(), false)) * -1
                    : null,
                'url' => route('risk.issues.show', $issue),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return Builder<Issue> */
    private function scoped(): Builder
    {
        return Issue::query()->where('organization_id', TenantContext::organizationId());
    }

    /** Everything that is not closed or cancelled. @return Builder<Issue> */
    private function open(): Builder
    {
        return $this->scoped()->whereNotIn('issue_status', self::SETTLED_STATUSES);
    }
}
