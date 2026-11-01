<?php

namespace App\Presenters\Kri;

use App\Enums\KriContactRole;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\User;
use App\Support\Kri\KriDepartmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The KRI Library grouped by department (ADR 0026 section 16): the landing
 * (`Kri/Index`, layout `departments`) and the department page (`Kri/Department`).
 *
 * NO N+1. The colour counts are ONE grouped query over active KRIs
 * (`key_risk_indicators.current_status`, the engine's mirror), the returns are
 * one query for the period, the line counts one grouped query, the Issue counts
 * one grouped query. Departments are the active business units in scope,
 * INCLUDING those with zero KRIs (decision 0.6); KRIs with no department go in
 * the "Unassigned" tile, which every `kri.view` holder sees, because an
 * unassigned KRI is not unit-scoped.
 */
class KriLibraryPresenter
{
    public function __construct(
        private readonly KriDepartmentScope $scope,
        private readonly KriReturnPresenter $returns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function landing(User $user): array
    {
        $unitIds = $this->scope->unitIdsFor($user);
        $period = $this->returns->cyclePeriods()->first();

        $units = BusinessUnit::query()
            ->where('is_active', true)
            ->when($unitIds !== null, fn ($q) => $q->whereIn('id', $unitIds))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'parent_id']);

        $counts = $this->statusCounts();

        $periodReturns = $period === null
            ? collect()
            : KriReturn::query()->where('period_id', $period->id)->whereIn('business_unit_id', $units->pluck('id'))->get()->keyBy('business_unit_id');

        $completed = $this->returns->completedLineCounts($periodReturns->pluck('id')->map(fn ($id) => (int) $id)->all());
        $issueCounts = $this->returns->issueCountsByUnit($units->pluck('id')->map(fn ($id) => (int) $id)->all());

        return [
            'layout' => 'departments',
            'period' => $this->returns->periodOpt($period),
            'departments' => $units->map(function (BusinessUnit $unit) use ($counts, $periodReturns, $completed, $issueCounts) {
                $row = $counts[(int) $unit->id] ?? $this->emptyCounts();
                $return = $periodReturns->get($unit->id);

                return [
                    'id' => (int) $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                    'parent_id' => $unit->parent_id === null ? null : (int) $unit->parent_id,
                    'kri_count' => $row['kri_count'],
                    'red' => $row['red'],
                    'amber' => $row['amber'],
                    'green' => $row['green'],
                    'unbanded' => $row['unbanded'],
                    'not_read' => $row['not_read'],
                    'return' => $return === null ? null : $this->returns->ref($return, ($completed[(int) $return->id] ?? 0) > 0),
                    'open_kri_issues' => $issueCounts[(int) $unit->id]['open'] ?? 0,
                    'overdue_kri_issues' => $issueCounts[(int) $unit->id]['overdue'] ?? 0,
                    'url' => route('risk.kri.departments.show', $unit),
                ];
            })->values()->all(),
            'unassigned' => $this->unassigned($counts),
            'scopeNote' => $this->scope->describe($user),
            'flatUrl' => route('risk.kri.index', ['layout' => 'flat']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function department(User $user, ?BusinessUnit $unit): array
    {
        $counts = $this->statusCounts($unit?->id);
        $row = $unit === null ? ($counts[0] ?? $this->emptyCounts()) : ($counts[(int) $unit->id] ?? $this->emptyCounts());

        $period = $this->returns->cyclePeriods()->first();
        $return = ($unit === null || $period === null)
            ? null
            : KriReturn::query()->where('period_id', $period->id)->where('business_unit_id', $unit->id)->first();

        $completed = $return === null ? [] : $this->returns->completedLineCounts([(int) $return->id]);

        return [
            'department' => $unit === null ? null : [
                'id' => (int) $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'parent' => $unit->parent === null ? null : ['id' => (int) $unit->parent->id, 'name' => $unit->parent->name],
            ],
            'summary' => $row,
            'return' => $return === null ? null : $this->returns->ref($return, ($completed[(int) $return->id] ?? 0) > 0),
            'openKriIssues' => $this->returns->openIssueRows($unit?->id),
            // For Unassigned there is no department, so no contacts (A1.10).
            'contacts' => $unit === null
                ? null
                : ($this->returns->contactsByUnit([(int) $unit->id])[(int) $unit->id] ?? array_fill_keys(KriContactRole::values(), [])),
        ];
    }

    /**
     * Active, non-deleted KRIs counted by their engine-mirrored status, grouped
     * by department. Key 0 is the Unassigned group.
     *
     * Only grouped columns and aggregates are selected (ONLY_FULL_GROUP_BY).
     * `yellow` folds into green, as KriService::statusDistribution does.
     *
     * @return array<int, array{kri_count: int, red: int, amber: int, green: int, unbanded: int, not_read: int}>
     */
    private function statusCounts(?int $unitId = null): array
    {
        // A1.4: a KRI HAS A READING when either last-measured column is set or it
        // appears in kri_measurements. A derived table of distinct KRI ids, valid
        // on both engines, with no correlated subquery inside an aggregate. A
        // never-read KRI is not green: it is counted as not_read.
        $has = '(key_risk_indicators.last_measurement_at IS NOT NULL OR key_risk_indicators.last_measurement_date IS NOT NULL OR km.kri_id IS NOT NULL)';

        $rows = KeyRiskIndicator::query()
            ->visibleTo()
            ->leftJoinSub(DB::table('kri_measurements')->select('kri_id')->distinct(), 'km', 'km.kri_id', '=', 'key_risk_indicators.id')
            ->where(fn (Builder $q) => $q->where('key_risk_indicators.is_active', true)->orWhereNull('key_risk_indicators.is_active'))
            ->when($unitId !== null, fn (Builder $q) => $q->where('key_risk_indicators.business_unit_id', $unitId))
            ->selectRaw(
                'key_risk_indicators.business_unit_id as unit_id, COUNT(*) as kri_count, '
                ."SUM(CASE WHEN {$has} THEN 1 ELSE 0 END) as read_count, "
                ."SUM(CASE WHEN {$has} AND key_risk_indicators.current_status = 'red' THEN 1 ELSE 0 END) as red_count, "
                ."SUM(CASE WHEN {$has} AND key_risk_indicators.current_status = 'amber' THEN 1 ELSE 0 END) as amber_count, "
                ."SUM(CASE WHEN {$has} AND key_risk_indicators.current_status IN ('green', 'yellow') THEN 1 ELSE 0 END) as green_count"
            )
            ->groupBy('key_risk_indicators.business_unit_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $total = (int) $row->kri_count;
            $read = (int) $row->read_count;
            $red = (int) $row->red_count;
            $amber = (int) $row->amber_count;
            $green = (int) $row->green_count;

            $out[(int) ($row->unit_id ?? 0)] = [
                'kri_count' => $total,
                'red' => $red,
                'amber' => $amber,
                'green' => $green,
                'unbanded' => $read - $red - $amber - $green,
                'not_read' => $total - $read,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{kri_count: int, red: int, amber: int, green: int, unbanded: int, not_read: int}>  $counts
     * @return array<string, mixed>
     */
    private function unassigned(array $counts): array
    {
        return ($counts[0] ?? $this->emptyCounts()) + ['url' => route('risk.kri.departments.unassigned')];
    }

    /** @return array{kri_count: int, red: int, amber: int, green: int, unbanded: int, not_read: int} */
    private function emptyCounts(): array
    {
        return ['kri_count' => 0, 'red' => 0, 'amber' => 0, 'green' => 0, 'unbanded' => 0, 'not_read' => 0];
    }
}
