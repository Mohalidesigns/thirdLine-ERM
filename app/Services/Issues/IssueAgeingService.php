<?php

namespace App\Services\Issues;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The ageing report (migration Phase 4.4).
 *
 * Lifted out of IssueController::ageingReport(), which loaded every open issue,
 * decorated each one with two computed attributes, built the priority×band
 * matrix in PHP, and then ran twelve more COUNT queries for the six-month
 * trend.
 *
 * The matrix and the bands still come from the loaded rows — the age of an
 * issue is a PHP calculation over `created_at` and there is no portable SQL for
 * it that is worth the loss of clarity — but the trend is two grouped queries
 * rather than twelve counts.
 */
class IssueAgeingService
{
    /** The four bands, in order. @var list<string> */
    public const BANDS = ['0-30 days', '31-60 days', '61-90 days', '90+ days'];

    /** @var list<string> */
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * Every open issue with its age and band.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openIssues(): Collection
    {
        return $this->open()
            ->with(['issueOwner', 'businessUnit'])
            ->orderBy('created_at')
            ->get()
            ->map(function (Issue $issue): array {
                $age = (int) $issue->created_at->startOfDay()->diffInDays(now()->startOfDay());

                /** @var array<string, mixed> $row */
                $row = [
                    'id' => $issue->id,
                    'reference' => $issue->issue_reference,
                    'title' => $issue->title,
                    // `issue_priority` is not a column and never was; the Blade
                    // report read it first and fell through to `priority` every
                    // time.
                    'priority' => $this->normalisePriority($issue->priority),
                    'status' => $issue->issue_status,
                    'owner' => $issue->issueOwner?->name,
                    'businessUnit' => $issue->businessUnit?->name,
                    'dueDate' => $issue->remediation_due_date?->format('d M Y'),
                    'ageDays' => $age,
                    'band' => $this->bandFor($age),
                    'url' => route('risk.issues.show', $issue),
                ];

                return $row;
            });
    }

    /**
     * Counts per band.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array{label: string, value: int}>
     */
    public function bandSummary(Collection $issues): array
    {
        return array_map(fn (string $band) => [
            'label' => $band,
            'value' => $issues->where('band', $band)->count(),
        ], self::BANDS);
    }

    /**
     * The priority × band matrix, always four by four so the chart's shape does
     * not change with the data.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array{priority: string, bands: list<int>, total: int}>
     */
    public function matrix(Collection $issues): array
    {
        return array_map(function (string $priority) use ($issues) {
            $forPriority = $issues->where('priority', $priority);

            $bands = array_map(
                fn (string $band) => $forPriority->where('band', $band)->count(),
                self::BANDS,
            );

            return [
                'priority' => $priority,
                'bands' => $bands,
                'total' => array_sum($bands),
            ];
        }, self::PRIORITIES);
    }

    /**
     * Issues opened and closed per month over the trailing six.
     *
     * @return list<array{month: string, opened: int, closed: int}>
     */
    public function trend(int $months = 6): array
    {
        $since = now()->copy()->startOfMonth()->subMonths($months - 1);

        $opened = $this->countByMonth($this->scoped()->where('created_at', '>=', $since), 'created_at');
        $closed = $this->countByMonth(
            $this->scoped()->where('issue_status', 'CLOSED')->where('updated_at', '>=', $since),
            'updated_at',
        );

        $result = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = now()->copy()->startOfMonth()->subMonths($offset);
            $key = $month->format('Y-m');

            $result[] = [
                'month' => $month->format('M'),
                'opened' => $opened[$key] ?? 0,
                'closed' => $closed[$key] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * The fifteen oldest.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public function oldest(Collection $issues, int $limit = 15): array
    {
        return $issues->sortByDesc('ageDays')->take($limit)->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return Builder<Issue> */
    private function scoped(): Builder
    {
        return Issue::query()->where('organization_id', TenantContext::organizationId());
    }

    /** @return Builder<Issue> */
    private function open(): Builder
    {
        return $this->scoped()->whereNotIn('issue_status', IssueDashboardService::SETTLED_STATUSES);
    }

    private function bandFor(int $days): string
    {
        return match (true) {
            $days <= 30 => self::BANDS[0],
            $days <= 60 => self::BANDS[1],
            $days <= 90 => self::BANDS[2],
            default => self::BANDS[3],
        };
    }

    /** An unrecognised priority reads as medium, as it did. */
    private function normalisePriority(?string $priority): string
    {
        $priority = strtolower((string) $priority);

        return in_array($priority, self::PRIORITIES, true) ? $priority : 'medium';
    }

    /**
     * @param  Builder<Issue>  $query
     * @return array<string, int>
     */
    private function countByMonth(Builder $query, string $column): array
    {
        return $query->selectRaw($this->monthKey($column).' as month_key, COUNT(*) as total')
            ->groupBy('month_key')
            ->pluck('total', 'month_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** MySQL and SQLite disagree on formatting a date; this runs on both. */
    private function monthKey(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
