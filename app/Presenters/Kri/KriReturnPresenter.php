<?php

namespace App\Presenters\Kri;

use App\Enums\KriContactRole;
use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriCycleRun;
use App\Models\KriDepartmentContact;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\KriReturnNotice;
use App\Models\Period;
use App\Models\User;
use App\Services\Kri\KriRedBandService;
use App\Services\KriMeasureBridge;
use App\Services\PeriodService;
use App\Support\Kri\KriBandRule;
use App\Support\Kri\KriContactLadder;
use App\Support\Kri\KriDepartmentScope;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The page props of the departmental KRI cycle's return screens (ADR 0026
 * section 16): the dashboard card, the drill-down and "Complete my KRIs".
 *
 * EVERY COUNT IS A GROUPED QUERY. No per-row query on the card or the
 * drill-down, no window functions, no CTEs, no JSON functions, and every
 * grouped select names only grouped columns and aggregates (MySQL 8
 * ONLY_FULL_GROUP_BY). A rate over an empty set is null, not 0; the figures
 * here are counts, and a count of nothing is truly 0.
 */
class KriReturnPresenter
{
    public function __construct(
        private readonly KriDepartmentScope $scope,
        private readonly KriBandRule $bands,
        private readonly KriRedBandService $redBand,
        private readonly KriMeasureBridge $bridge,
        private readonly PeriodService $periods,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Shared shapes */
    /* ------------------------------------------------------------------ */

    /** @return array{id: int, label: string}|null */
    public function periodOpt(?Period $period): ?array
    {
        return $period === null ? null : ['id' => (int) $period->id, 'label' => (string) $period->name];
    }

    /**
     * ReturnRef = {id, status, display_status, due_on, days_overdue|null, url}.
     *
     * @return array<string, mixed>
     */
    public function ref(KriReturn $return, bool $hasValue): array
    {
        $today = KriWorkingCalendar::today();

        return [
            'id' => (int) $return->id,
            'status' => $return->status->value,
            'display_status' => $return->displayStatus($today, $hasValue),
            'due_on' => $return->due_on->toDateString(),
            'days_overdue' => $return->daysOverdue($today),
            'url' => route('risk.kri.returns.show', $return),
        ];
    }

    /**
     * Returns that have at least one line holding a value, as a set of return ids.
     *
     * @param  list<int>  $returnIds
     * @return array<int, int> return id => lines with a value
     */
    public function completedLineCounts(array $returnIds): array
    {
        if ($returnIds === []) {
            return [];
        }

        return KriReturnLine::query()
            ->whereIn('kri_return_id', $returnIds)
            ->whereNotNull('value')
            ->selectRaw('kri_return_id, COUNT(*) as completed_lines')
            ->groupBy('kri_return_id')
            ->pluck('completed_lines', 'kri_return_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return Collection<int, Period> periods that have a cycle run, newest first, at most 12 */
    public function cyclePeriods(): Collection
    {
        return Period::query()
            ->whereIn('id', KriCycleRun::query()->select('period_id'))
            ->orderByDesc('end_date')
            ->limit(12)
            ->get();
    }

    /**
     * ReturnSummary = {denominator, submitted, not_submitted, overdue, no_champion}.
     * The denominator is the set of returns for the period inside the user's scope
     * (decision 0.6): a department with no due KRIs has no return and is not in it.
     *
     * @return array{denominator: int, submitted: int, not_submitted: int, overdue: int, no_champion: int}
     */
    public function summary(?Period $period, ?User $user): array
    {
        $empty = ['denominator' => 0, 'submitted' => 0, 'not_submitted' => 0, 'overdue' => 0, 'no_champion' => 0];

        if ($period === null) {
            return $empty;
        }

        $base = fn () => $this->scope->apply(KriReturn::query()->where('period_id', $period->id), $user);

        $row = $base()
            ->selectRaw(
                "COUNT(*) as total_returns, "
                ."SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_returns, "
                ."SUM(CASE WHEN status <> 'submitted' AND due_on < ? THEN 1 ELSE 0 END) as overdue_returns",
                [KriWorkingCalendar::today()->toDateString()],
            )
            ->first();

        $total = (int) ($row->total_returns ?? 0);
        $submitted = (int) ($row->submitted_returns ?? 0);

        $noChampion = $total === 0 ? 0 : $base()
            ->whereNotIn('business_unit_id', KriDepartmentContact::query()
                ->where('role', KriContactRole::RiskChampion->value)
                ->select('business_unit_id'))
            ->count();

        return [
            'denominator' => $total,
            'submitted' => $submitted,
            'not_submitted' => $total - $submitted,
            'overdue' => (int) ($row->overdue_returns ?? 0),
            'no_champion' => $noChampion,
        ];
    }

    /**
     * Open KRI Issues (and how many are overdue) per department.
     *
     * @param  list<int>|null  $unitIds  null = every unit with such an Issue
     * @return array<int, array{open: int, overdue: int}> keyed by business unit id
     */
    public function issueCountsByUnit(?array $unitIds = null): array
    {
        $rows = Issue::query()
            ->where('issue_source', KriRedBandService::ISSUE_SOURCE)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->whereNotNull('business_unit_id')
            ->when($unitIds !== null, fn ($q) => $q->whereIn('business_unit_id', $unitIds))
            ->selectRaw(
                'business_unit_id, COUNT(*) as open_issues, '
                .'SUM(CASE WHEN remediation_due_date IS NOT NULL AND remediation_due_date < ? THEN 1 ELSE 0 END) as overdue_issues',
                [KriWorkingCalendar::today()->toDateString()],
            )
            ->groupBy('business_unit_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->business_unit_id] = ['open' => (int) $row->open_issues, 'overdue' => (int) $row->overdue_issues];
        }

        return $out;
    }

    /**
     * The department's open KRI Issues, for the department page and "Complete my KRIs".
     *
     * @return list<array<string, mixed>>
     */
    public function openIssueRows(?int $unitId, ?User $viewer = null): array
    {
        $today = KriWorkingCalendar::today()->toDateString();

        return Issue::query()
            ->where('issue_source', KriRedBandService::ISSUE_SOURCE)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->when($unitId === null, fn ($q) => $q->whereNull('business_unit_id'), fn ($q) => $q->where('business_unit_id', $unitId))
            ->orderBy('remediation_due_date')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => (int) $issue->id,
                'reference' => $issue->issue_reference,
                'title' => $issue->title,
                'status' => $issue->issue_status,
                'due_date' => $issue->remediation_due_date?->toDateString(),
                'is_overdue' => $issue->remediation_due_date !== null && $issue->remediation_due_date->toDateString() < $today,
                // A1.1: a read-only viewer without access to the Issue gets no link.
                'url' => $viewer === null || $viewer->can('view', $issue) ? route('risk.issues.show', $issue) : null,
            ])
            ->all();
    }

    /** @return array{id: int, name: string, email: string, is_active: bool} */
    public function contact(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_active' => (bool) $user->is_active,
        ];
    }

    /**
     * A department's contacts grouped by role, in the ladder's order.
     *
     * @param  list<int>  $unitIds
     * @return array<int, array<string, list<array{id: int, name: string, email: string, is_active: bool}>>> unit id => role => Contact[]
     */
    public function contactsByUnit(array $unitIds): array
    {
        $out = [];

        foreach ($unitIds as $id) {
            $out[$id] = array_fill_keys(KriContactRole::values(), []);
        }

        if ($unitIds === []) {
            return $out;
        }

        KriDepartmentContact::query()
            ->whereIn('business_unit_id', $unitIds)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->each(function (KriDepartmentContact $contact) use (&$out) {
                if ($contact->user !== null) {
                    $out[(int) $contact->business_unit_id][$contact->role->value][] = $this->contact($contact->user);
                }
            });

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard card */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function card(User $user, ?int $requestedPeriodId): array
    {
        $periods = $this->cyclePeriods();
        $selected = $periods->firstWhere('id', $requestedPeriodId) ?? $periods->first();

        return [
            'period' => $this->periodOpt($selected),
            'periods' => $periods->map(fn (Period $p) => $this->periodOpt($p))->values()->all(),
            'summary' => $this->summary($selected, $user),
            'cycleOpened' => $selected !== null && KriCycleRun::query()
                ->where('period_id', $selected->id)
                ->where('status', KriCycleRun::STATUS_COMPLETED)
                ->exists(),
            'drillDownUrl' => route('risk.kri.returns.index', $selected === null ? [] : ['period' => $selected->id]),
            'canRemind' => $user->can('kri.send_reminder'),
            'scopeNote' => $this->scope->describe($user),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Drill-down */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function drillDown(User $user, ?int $requestedPeriodId, string $filter): array
    {
        $filter = in_array($filter, ['not_submitted', 'submitted', 'all'], true) ? $filter : 'not_submitted';
        $periods = $this->cyclePeriods();
        $period = $periods->firstWhere('id', $requestedPeriodId) ?? $periods->first();
        $today = KriWorkingCalendar::today();

        $payload = [
            'period' => $this->periodOpt($period),
            'periods' => $periods->map(fn (Period $p) => $this->periodOpt($p))->values()->all(),
            'filter' => $filter,
            'summary' => $this->summary($period, $user),
            'rows' => [],
            'canRemind' => $user->can('kri.send_reminder'),
            'scopeNote' => $this->scope->describe($user),
        ];

        if ($period === null) {
            return $payload;
        }

        $returns = $this->scope->apply(KriReturn::query()->where('period_id', $period->id), $user)
            ->with('businessUnit:id,code,name')
            ->when($filter === 'not_submitted', fn ($q) => $q->where('status', '<>', 'submitted'))
            ->when($filter === 'submitted', fn ($q) => $q->where('status', 'submitted'))
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        $ids = $returns->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unitIds = $returns->pluck('business_unit_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $lineTotals = $ids === [] ? collect() : KriReturnLine::query()
            ->whereIn('kri_return_id', $ids)
            ->selectRaw('kri_return_id, COUNT(*) as total_lines, SUM(CASE WHEN value IS NOT NULL THEN 1 ELSE 0 END) as done_lines')
            ->groupBy('kri_return_id')
            ->get()
            ->keyBy('kri_return_id');

        $reminders = $ids === [] ? collect() : KriReturnNotice::query()
            ->whereIn('kri_return_id', $ids)
            ->where('kind', KriReturnNotice::KIND_REMINDER)
            ->selectRaw('kri_return_id, COUNT(*) as reminders, MAX(id) as last_id')
            ->groupBy('kri_return_id')
            ->get()
            ->keyBy('kri_return_id');

        $remindedToday = $unitIds === [] ? [] : KriReturnNotice::query()
            ->whereIn('business_unit_id', $unitIds)
            ->where('kind', KriReturnNotice::KIND_REMINDER)
            ->where('sent_on', $today->toDateString())
            ->pluck('business_unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // A1.5: one query for the page's notices, sliced in PHP to the latest 3
        // per return (no window function), then one query for the recipients of
        // the notices kept. The full history loads on expand from `notices()`.
        $allNotices = $ids === [] ? collect() : KriReturnNotice::query()
            ->whereIn('kri_return_id', $ids)
            ->with('requester:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('kri_return_id');

        $kept = $allNotices->map(fn ($group) => $group->take(3));
        $recipients = $kept->isEmpty() ? collect() : \App\Models\KriReturnNoticeRecipient::query()
            ->whereIn('kri_return_notice_id', $kept->flatten()->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('kri_return_notice_id');
        $contactCounts = $this->contactCounts($unitIds);

        $contacts = $this->contactsByUnit($unitIds);
        $issueCounts = $this->issueCountsByUnit($unitIds);

        $payload['rows'] = $returns->map(function (KriReturn $return) use ($lineTotals, $reminders, $remindedToday, $allNotices, $kept, $recipients, $contactCounts, $contacts, $issueCounts, $today) {
            $unitId = (int) $return->business_unit_id;
            $totals = $lineTotals->get($return->id);
            $done = (int) ($totals->done_lines ?? 0);
            $remindCount = (int) ($reminders->get($return->id)->reminders ?? 0);
            $champions = $contacts[$unitId][KriContactRole::RiskChampion->value] ?? [];
            $unitNotices = $kept->get($return->id, collect());
            $last = $allNotices->get($return->id, collect())->where('kind', KriReturnNotice::KIND_REMINDER)->first();

            return [
                'return_id' => (int) $return->id,
                'department' => [
                    'id' => $unitId,
                    'code' => $return->businessUnit?->code,
                    'name' => $return->businessUnit?->name,
                ],
                'status' => $return->status->value,
                'display_status' => $return->displayStatus($today, $done > 0),
                'due_on' => $return->due_on->toDateString(),
                'days_overdue' => $return->daysOverdue($today),
                'kris_due' => (int) ($totals->total_lines ?? 0),
                'kris_completed' => $done,
                'champions' => $champions,
                'has_champion' => $champions !== [],
                'reminder_count' => $remindCount,
                'reminded_today' => in_array($unitId, $remindedToday, true),
                'next_tier' => KriContactLadder::tierForSequence($remindCount + 1),
                'last_reminder' => $last === null ? null : [
                    'sent_on' => $last->sent_on->toDateString(),
                    'sent_by' => $last->requester === null ? null : ['id' => (int) $last->requester->id, 'name' => $last->requester->name],
                    'tier' => (int) $last->tier,
                    'status' => $last->status,
                ],
                'open_kri_issues' => $issueCounts[$unitId]['open'] ?? 0,
                'overdue_kri_issues' => $issueCounts[$unitId]['overdue'] ?? 0,
                'notices' => $unitNotices->map(fn (KriReturnNotice $notice) => $this->noticeRow($notice, $recipients->get($notice->id, collect())))->values()->all(),
                'notices_total' => $allNotices->get($return->id, collect())->count(),
                'contact_counts' => $contactCounts[$unitId] ?? array_fill_keys(KriContactRole::values(), 0),
                'view_url' => route('risk.kri.returns.show', $return),
            ];
        })->sort(function (array $a, array $b) {
            // A1.6: display_status rank, then days overdue descending, then name.
            $rank = ['overdue' => 0, 'reopened' => 1, 'in_progress' => 2, 'not_started' => 3, 'submitted' => 4];

            return [$rank[$a['display_status']] ?? 9, -($a['days_overdue'] ?? 0), (string) $a['department']['name']]
                <=> [$rank[$b['display_status']] ?? 9, -($b['days_overdue'] ?? 0), (string) $b['department']['name']];
        })->values()->all();

        return $payload;
    }

    /**
     * The full notice history of one return, newest first, each with recipients
     * (the JSON behind `risk.kri.returns.notices`).
     *
     * @return list<array<string, mixed>>
     */
    public function allNotices(KriReturn $return): array
    {
        $notices = KriReturnNotice::query()->where('kri_return_id', $return->id)->with('requester:id,name')->orderByDesc('id')->get();
        $recipients = \App\Models\KriReturnNoticeRecipient::query()
            ->whereIn('kri_return_notice_id', $notices->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('kri_return_notice_id');

        return $notices->map(fn (KriReturnNotice $n) => $this->noticeRow($n, $recipients->get($n->id, collect())))->values()->all();
    }

    /** @param \Illuminate\Support\Collection<int, \App\Models\KriReturnNoticeRecipient> $recipients */
    private function noticeRow(KriReturnNotice $notice, $recipients): array
    {
        return [
            'id' => (int) $notice->id,
            'kind' => $notice->kind,
            'sequence' => (int) $notice->sequence,
            'tier' => (int) $notice->tier,
            'status' => $notice->status,
            'sent_on' => $notice->sent_on->toDateString(),
            'queued_at' => $notice->queued_at?->toIso8601String(),
            'sent_by' => $notice->requester === null ? null : ['id' => (int) $notice->requester->id, 'name' => $notice->requester->name],
            // `sent` means accepted by the mail transport, never "in an inbox".
            'recipients' => $recipients->map(fn ($r) => [
                'name' => $r->name,
                'email' => $r->email,
                'contact_role' => $r->contact_role,
                'status' => $r->status,
                'skip_reason' => $r->skip_reason,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'error' => $r->error,
            ])->values()->all(),
        ];
    }

    /**
     * Contacts who COULD receive a reminder (active, with an email), per
     * department and role: one grouped query (A1.6).
     *
     * @param  list<int>  $unitIds
     * @return array<int, array<string, int>>
     */
    private function contactCounts(array $unitIds): array
    {
        $out = [];

        foreach ($unitIds as $id) {
            $out[$id] = array_fill_keys(KriContactRole::values(), 0);
        }

        if ($unitIds === []) {
            return $out;
        }

        KriDepartmentContact::query()
            ->join('users', 'users.id', '=', 'kri_department_contacts.user_id')
            ->whereIn('kri_department_contacts.business_unit_id', $unitIds)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereNotNull('users.email')
            ->where('users.email', '<>', '')
            ->selectRaw('kri_department_contacts.business_unit_id as unit_id, kri_department_contacts.role as contact_role, COUNT(*) as total')
            ->groupBy('kri_department_contacts.business_unit_id', 'kri_department_contacts.role')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->unit_id][$row->contact_role] = (int) $row->total;
            });

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Complete my KRIs */
    /* ------------------------------------------------------------------ */

    /**
     * The "not configured" / "not open" / "empty" shells, with no return.
     *
     * @param  list<int>  $championUnitIds
     * @return array<string, mixed>
     */
    public function myReturnShell(string $state, array $championUnitIds, ?BusinessUnit $department = null, ?Period $period = null, bool $readOnly = false): array
    {
        return [
            'readOnly' => $readOnly,
            'state' => $state,
            'opensOn' => $this->opensOn($period),
            'departments' => $this->departmentLinks($championUnitIds),
            'department' => $this->departmentRef($department),
            'period' => $this->periodDetail($period),
            'return' => null,
            'progress' => ['completed' => 0, 'total' => 0],
            'lines' => [],
            'ownerOptions' => [],
            'openKriIssues' => [],
            'urls' => ['draft' => null, 'submit' => null, 'reopen' => null],
        ];
    }

    /**
     * @param  list<int>  $championUnitIds
     * @return array<string, mixed>
     */
    public function myReturn(User $user, KriReturn $return, array $championUnitIds, bool $readOnly, bool $periodClosed): array
    {
        $return->loadMissing(['businessUnit', 'period', 'submitter']);
        $today = KriWorkingCalendar::today();

        $lines = KriReturnLine::query()
            ->where('kri_return_id', $return->id)
            ->with(['kri', 'period'])
            ->get()
            ->filter(fn (KriReturnLine $line) => $line->kri !== null)
            ->sortBy(fn (KriReturnLine $line) => $line->kri->kri_code)
            ->values();

        $completed = $lines->filter(fn (KriReturnLine $l) => $l->value !== null)->count();
        $isChampion = in_array((int) $return->business_unit_id, $championUnitIds, true);
        $editable = ! $readOnly && $isChampion && ! $return->isSubmitted();
        $unitId = (int) $return->business_unit_id;
        // A1.1: a contact without kri.view reads the return but gets no links or actions.
        $canViewKris = $user->can('kri.view');

        return [
            'readOnly' => $readOnly,
            'state' => $lines->isEmpty() ? 'empty' : 'ok',
            'opensOn' => null,
            'departments' => $canViewKris || ! $readOnly ? $this->departmentLinks($championUnitIds) : [],
            'department' => $this->departmentRef($return->businessUnit),
            'period' => $this->periodDetail($return->period),
            'return' => [
                'id' => (int) $return->id,
                'status' => $return->status->value,
                'display_status' => $return->displayStatus($today, $completed > 0),
                'requested_on' => $return->requested_on->toDateString(),
                'due_on' => $return->due_on->toDateString(),
                'days_overdue' => $return->daysOverdue($today),
                'submitted_at' => $return->submitted_at?->toIso8601String(),
                'submitted_by' => $return->submitter === null ? null : ['id' => (int) $return->submitter->id, 'name' => $return->submitter->name],
                'reopened_at' => $return->reopened_at?->toIso8601String(),
                'lock_version' => (int) $return->lock_version,
                'period_closed' => $periodClosed,
                'can_save' => $editable,
                'can_submit' => $editable,
                'can_reopen' => ! $readOnly && $isChampion && $return->isSubmitted() && ! $periodClosed,
            ],
            'progress' => ['completed' => $completed, 'total' => $lines->count()],
            'lines' => $lines->map(fn (KriReturnLine $line) => $this->line($user, $line, $canViewKris))->all(),
            'ownerOptions' => $this->ownerOptions($user, $unitId),
            'openKriIssues' => $this->openIssueRows($unitId, $user),
            'urls' => $readOnly || ! $isChampion ? ['draft' => null, 'submit' => null, 'reopen' => null] : [
                'draft' => route('risk.kri.my-return.draft', $return),
                'submit' => route('risk.kri.my-return.submit', $return),
                'reopen' => route('risk.kri.my-return.reopen', $return),
            ],
        ];
    }

    /**
     * Line = {line_id, kri, bandRule, value, comment, band, previous, recorded_value, red_band, open_issue}.
     *
     * @return array<string, mixed>
     */
    private function line(User $user, KriReturnLine $line, bool $canViewKris = true): array
    {
        /** @var KeyRiskIndicator $kri */
        $kri = $line->kri;
        $period = $line->period;

        $previousPeriod = $period === null ? null : $this->periods->previous($period);
        $previous = null;

        if ($previousPeriod !== null) {
            $series = $this->bridge->series($kri, [(int) $previousPeriod->id]);
            $value = $series[$previousPeriod->id] ?? null;

            if ($value !== null) {
                $previous = [
                    'period_label' => $previousPeriod->name,
                    'value' => (float) $value,
                    'band' => $this->bands->bandForReading($kri, $previousPeriod, (float) $value),
                ];
            }
        }

        $recorded = $period === null ? null : $this->bridge->valueIn($kri, $period);
        $issue = $this->redBand->openIssueFor($kri);

        return [
            'line_id' => (int) $line->id,
            'kri' => [
                'id' => (int) $kri->id,
                'code' => $kri->kri_code,
                'name' => $kri->name,
                'description' => $kri->description,
                'unit' => $kri->unit_of_measure,
                'frequency' => $kri->measurement_frequency,
                'direction' => $kri->threshold_direction,
                'green_boundary' => $kri->green_threshold,
                'red_boundary' => $kri->red_threshold,
                'target_value' => $kri->target_value === null ? null : (float) $kri->target_value,
                // Only if the user may view the KRI: a champion usually cannot.
                'url' => $canViewKris && $user->can('view', $kri) ? route('risk.kri.show', $kri) : null,
            ],
            'bandRule' => $period === null ? ['direction' => 'higher_worse', 'bands' => []] : KriBandRule::withPeriod($this->bands->forReading($kri, $period), $period),
            'value' => $line->value === null ? null : (float) $line->value,
            'comment' => $line->comment,
            'band' => $line->band,
            'previous' => $previous,
            'recorded_value' => $recorded?->value === null ? null : (float) $recorded->value,
            'red_band' => [
                'root_cause' => $line->root_cause,
                'action_point' => $line->action_point,
                'timeline' => $line->timeline?->toDateString(),
                'owner_id' => $line->action_owner_id === null ? null : (int) $line->action_owner_id,
                'timeline_change_reason' => $line->timeline_change_reason,
            ],
            'open_issue' => $issue === null ? null : [
                'id' => (int) $issue->id,
                'reference' => $issue->issue_reference,
                'status' => $issue->issue_status,
                'due_date' => $issue->remediation_due_date?->toDateString(),
                'action_plan' => $issue->action_plan,
                'observation' => $issue->observation,
                'owner' => $issue->responsibleOwner === null ? null : ['id' => (int) $issue->responsibleOwner->id, 'name' => $issue->responsibleOwner->name],
                'is_overdue' => $issue->remediation_due_date !== null
                    && $issue->remediation_due_date->toDateString() < KriWorkingCalendar::today()->toDateString(),
                'url' => $user->can('view', $issue) ? route('risk.issues.show', $issue) : null,
            ],
        ];
    }

    /**
     * Who may own a red-band action in this department: the viewer, its KRI
     * contacts and its business_unit_user members.
     *
     * @return list<array{id: int, name: string}>
     */
    private function ownerOptions(User $user, int $unitId): array
    {
        $ids = KriDepartmentContact::query()->where('business_unit_id', $unitId)->pluck('user_id')->all();
        $ids = array_merge($ids, DB::table('business_unit_user')
            ->where('organization_id', $user->organization_id)
            ->where('business_unit_id', $unitId)
            ->pluck('user_id')
            ->all());
        $ids[] = $user->id;

        return User::query()
            ->whereIn('id', array_unique(array_map('intval', $ids)))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->all();
    }

    /**
     * @param  list<int>  $unitIds
     * @return list<array{id: int, name: string, url: string}>
     */
    private function departmentLinks(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return BusinessUnit::query()
            ->whereIn('id', $unitIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => [
                'id' => (int) $unit->id,
                'name' => $unit->name,
                'url' => route('risk.kri.my-return', ['department' => $unit->id]),
            ])
            ->all();
    }

    /**
     * The expected date the cycle opens for a period that has no return yet:
     * the first working day of the month after the requested period, on
     * today's holiday list. Labelled as expected, never promised (A1.10).
     */
    private function opensOn(?Period $period): ?string
    {
        if ($period === null) {
            return null;
        }

        $next = $period->end_date->copy()->addDay();

        return app(KriWorkingCalendar::class)->firstWorkingDayOf((int) $next->format('Y'), (int) $next->format('n'))->toDateString();
    }

    /** @return array{id: int, code: string|null, name: string}|null */
    private function departmentRef(?BusinessUnit $unit): ?array
    {
        return $unit === null ? null : ['id' => (int) $unit->id, 'code' => $unit->code, 'name' => $unit->name];
    }

    /** @return array{id: int, label: string, start_date: string, end_date: string}|null */
    private function periodDetail(?Period $period): ?array
    {
        return $period === null ? null : [
            'id' => (int) $period->id,
            'label' => (string) $period->name,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ];
    }
}
