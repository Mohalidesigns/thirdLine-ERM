<?php

namespace App\Services\Kri;

use App\Enums\KriContactRole;
use App\Enums\KriReturnStatus;
use App\Jobs\DispatchKriReturnNotice;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\KriCycleRun;
use App\Models\KriDepartmentContact;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\KriReturnNotice;
use App\Models\Period;
use App\Services\AuditTrailService;
use App\Services\KriMeasureBridge;
use App\Services\PeriodService;
use App\Support\Kri\KriWorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Opening the monthly cycle (ADR 0026 sections 0, 4, 11 and 12).
 *
 * IDEMPOTENCY COMES FROM ROWS, NOT FROM THE CRON. A re-run, a forced run or two
 * servers cannot open a second return (unique key on organisation, unit,
 * period) or send a second request (unique idempotency_key). `--force` never
 * means "send again".
 *
 * WHAT A RETURN COLLECTS: THE MONTH JUST ENDED. A return opened on the first
 * working day of November collects October's readings, against October's
 * Period (reportingPeriodFor). If the business meant the current month, that is
 * this one function and no schema.
 *
 * THE DUE RULE LIVES IN dueKrisFor() AND NOWHERE ELSE.
 */
class KriCycleService
{
    public function __construct(
        private readonly PeriodService $periods,
        private readonly KriMeasureBridge $bridge,
        private readonly KriWorkingCalendar $calendar,
        private readonly KriNoticeService $notices,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Which month, which KRIs */
    /* ------------------------------------------------------------------ */

    /**
     * The reporting month for a Lagos day: the month that ended before the
     * first of this day's month.
     */
    public function reportingPeriodFor(CarbonImmutable $day, ?int $organizationId = null): Period
    {
        $lastDayOfPreviousMonth = $day->setTimezone(KriWorkingCalendar::TIMEZONE)
            ->startOfMonth()
            ->subDay();

        return $this->periods->resolve($lastDayOfPreviousMonth->toDateString(), 'month', $organizationId);
    }

    /**
     * The KRIs due in a department's return, each with its OWN measurement
     * period (the month for monthly, daily and weekly KRIs; the quarter etc.
     * for the others).
     *
     * Due means all of: assigned DIRECTLY to the unit (no roll-up of child
     * units); active and not soft-deleted; NOT automated (a publisher
     * overwrites those each morning, and one reading must not have two writers);
     * and its frequency honoured - the measurement period containing the
     * reporting month's end date ends on that same date, so a quarterly KRI is
     * due only when its quarter ends that month. It uses the tenant's period
     * calendar, so a fiscal calendar works.
     *
     * @return Collection<int, array{kri: KeyRiskIndicator, period: Period}>
     */
    public function dueKrisFor(BusinessUnit|int $unit, Period $reportingMonth): Collection
    {
        $unitId = $unit instanceof BusinessUnit ? $unit->id : $unit;

        return $this->dueKrisByUnit($reportingMonth, [$unitId])->get($unitId, collect());
    }

    /**
     * The same rule for many units in one KRI query.
     *
     * @param  list<int>|null  $unitIds  null = every unit that has a KRI
     * @return Collection<int, Collection<int, array{kri: KeyRiskIndicator, period: Period}>> keyed by unit id
     */
    public function dueKrisByUnit(Period $reportingMonth, ?array $unitIds = null): Collection
    {
        $kris = KeyRiskIndicator::query()
            ->whereNotNull('business_unit_id')
            ->when($unitIds !== null, fn ($q) => $q->whereIn('business_unit_id', $unitIds))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->where(fn ($q) => $q->where('is_automated', false)->orWhereNull('is_automated'))
            ->orderBy('kri_code')
            ->get();

        $end = $reportingMonth->end_date->toDateString();
        $byType = [];

        return $kris->map(function (KeyRiskIndicator $kri) use ($reportingMonth, $end, &$byType) {
            $type = $this->bridge->periodTypeFor($kri);
            $byType[$type] ??= $this->periods->resolve($end, $type, (int) $reportingMonth->organization_id);
            $period = $byType[$type];

            return $period->end_date->toDateString() === $end ? ['kri' => $kri, 'period' => $period] : null;
        })->filter()->groupBy(fn (array $row) => (int) $row['kri']->business_unit_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Line synchronisation */
    /* ------------------------------------------------------------------ */

    /**
     * Bring a return's lines in step with what is due: newly due KRIs gain a
     * line; a line whose KRI is no longer due is deleted, and a discarded draft
     * VALUE leaves an audit row. A submitted return's lines are frozen.
     *
     * @param  Collection<int, array{kri: KeyRiskIndicator, period: Period}>|null  $due  precomputed rule output
     * @return Collection<int, KriReturnLine>
     */
    public function syncLines(KriReturn $return, ?Collection $due = null): Collection
    {
        if ($return->isSubmitted()) {
            return $return->lines()->get();
        }

        $due ??= $this->dueKrisFor((int) $return->business_unit_id, $return->period ?? Period::query()->findOrFail($return->period_id));
        $dueByKri = $due->keyBy(fn (array $row) => (int) $row['kri']->id);

        $lines = KriReturnLine::query()->where('kri_return_id', $return->id)->get()->keyBy('key_risk_indicator_id');
        $changed = false;

        foreach ($dueByKri as $kriId => $row) {
            $line = $lines->get($kriId);

            if ($line === null) {
                KriReturnLine::query()->create([
                    'organization_id' => $return->organization_id,
                    'kri_return_id' => $return->id,
                    'key_risk_indicator_id' => $kriId,
                    'period_id' => $row['period']->id,
                ]);
                $changed = true;
            } elseif ((int) $line->period_id !== (int) $row['period']->id) {
                $line->forceFill(['period_id' => $row['period']->id])->save();
            }
        }

        $stale = $lines->filter(fn (KriReturnLine $line, $kriId) => ! $dueByKri->has((int) $kriId));

        if ($stale->isNotEmpty()) {
            foreach ($stale as $line) {
                if ($line->value !== null) {
                    $code = KeyRiskIndicator::query()->withTrashed()->whereKey($line->key_risk_indicator_id)->value('kri_code');

                    AuditTrailService::record(
                        $return,
                        'line_discarded',
                        'kri:'.($code ?? $line->key_risk_indicator_id),
                        (string) $line->value,
                        null,
                    );
                }
            }

            KriReturnLine::query()->whereIn('id', $stale->pluck('id')->all())->delete();
            $changed = true;
        }

        // A1.8: a sync that changes lines is a write another editor must notice.
        if ($changed && ! $return->wasRecentlyCreated) {
            KriReturn::query()->whereKey($return->id)->increment('lock_version');
            $return->lock_version = (int) $return->lock_version + 1;
        }

        return KriReturnLine::query()->where('kri_return_id', $return->id)->orderBy('key_risk_indicator_id')->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Opening the cycle for one organisation */
    /* ------------------------------------------------------------------ */

    /**
     * Open the cycle for the current tenant (the caller sets TenantContext).
     *
     * @return array{
     *   status: string, reason: ?string, period: ?Period, day: string, run: ?KriCycleRun,
     *   departments: list<array<string, mixed>>, departments_due: int, returns_opened: int,
     *   requests_queued: int, requests_redispatched: int, requests_not_queued: int,
     *   departments_without_champion: int, errors: list<string>
     * }
     */
    public function open(
        int $organizationId,
        CarbonImmutable $day,
        bool $force = false,
        bool $dryRun = false,
        string $trigger = 'schedule',
        ?int $triggeredBy = null,
    ): array {
        $report = [
            'status' => 'completed', 'reason' => null, 'period' => null, 'day' => $day->toDateString(),
            'run' => null, 'departments' => [], 'departments_due' => 0, 'returns_opened' => 0,
            'requests_queued' => 0, 'requests_redispatched' => 0, 'requests_not_queued' => 0,
            'departments_without_champion' => 0, 'errors' => [],
        ];

        $first = $this->calendar->firstWorkingDayOf((int) $day->format('Y'), (int) $day->format('n'), $organizationId);

        if (! $force && $day->toDateString() !== $first->toDateString()) {
            $report['status'] = 'skipped';
            $report['reason'] = 'not the first working day ('.$first->toDateString().')';

            return $report;
        }

        // --dry-run WRITES NOTHING, and "nothing" includes what the read path
        // writes on the way: PeriodService::resolve() creates a missing period
        // calendar and generates fiscal years. The whole dry run is therefore a
        // transaction that is always rolled back, so the promise holds however
        // the read path changes.
        if ($dryRun) {
            DB::beginTransaction();

            try {
                $period = $this->reportingPeriodFor($day, $organizationId);
                $report['period'] = $period;
                $this->planDepartments($period, $report);
                $report['status'] = 'dry_run';
            } finally {
                DB::rollBack();
            }

            return $report;
        }

        $period = $this->reportingPeriodFor($day, $organizationId);
        $report['period'] = $period;

        // WRITE AHEAD, BEFORE ANY OTHER WORK. The run row is what the watchdog
        // reads; it goes in as `running` before the due rule is evaluated, so a
        // throw anywhere after this line leaves a row that ends `failed` (or,
        // on a hard kill, stays `running` and trips the 60-minute check) rather
        // than no row at all.
        $run = $this->startRun($organizationId, $period, $day, $force, $trigger, $triggeredBy);
        $report['run'] = $run;

        try {
            [$units, $dueByUnit] = $this->planDepartments($period, $report);
            $report['departments_without_champion'] = 0;

            foreach ($units as $unit) {
                try {
                    $outcome = $this->openReturn($unit, $period, $day, $run, $dueByUnit->get((int) $unit->id));
                    $report['returns_opened'] += $outcome['opened'] ? 1 : 0;
                    $report['requests_queued'] += $outcome['request_queued'] ? 1 : 0;
                    $report['requests_redispatched'] += $outcome['request_redispatched'] ? 1 : 0;
                    $report['requests_not_queued'] += $outcome['dispatch_failed'] ? 1 : 0;
                    $report['departments_without_champion'] += $outcome['has_champion'] ? 0 : 1;
                } catch (Throwable $e) {
                    $report['errors'][] = $unit->name.': '.$e->getMessage();
                    report($e);
                }
            }
        } catch (Throwable $e) {
            // The due rule itself failed (a period that will not resolve, a
            // lost connection). Nothing per-department ran; the run says so.
            $report['errors'][] = 'Cycle could not be planned: '.$e->getMessage();
            report($e);
        }

        // Counts ACCUMULATE across re-runs of the same period: the row is
        // reused (one per organisation and period), and a forced re-run that
        // opens nothing new must not overwrite "20 returns opened" with 0.
        $run->forceFill([
            'status' => $report['errors'] === [] ? KriCycleRun::STATUS_COMPLETED : KriCycleRun::STATUS_FAILED,
            'finished_at' => now(),
            'departments_due' => $report['departments_due'],
            'returns_opened' => (int) $run->getOriginal('returns_opened') + $report['returns_opened'],
            'requests_queued' => (int) $run->getOriginal('requests_queued') + $report['requests_queued'],
            'departments_without_champion' => $report['departments_without_champion'],
            'error' => $report['errors'] === [] ? null : mb_substr(implode("\n", $report['errors']), 0, 60000),
        ])->save();

        $report['status'] = $run->status;

        return $report;
    }

    /**
     * The departments due this period and who champions them; fills the
     * report's department list. Read-only apart from PeriodService's lazy
     * calendar generation.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: Collection<int, BusinessUnit>, 1: Collection<int, Collection<int, array{kri: KeyRiskIndicator, period: Period}>>}
     */
    private function planDepartments(Period $period, array &$report): array
    {
        $units = BusinessUnit::query()->where('is_active', true)->orderBy('name')->get();
        $dueByUnit = $this->dueKrisByUnit($period, $units->pluck('id')->map(fn ($id) => (int) $id)->all());
        $units = $units->filter(fn (BusinessUnit $unit) => $dueByUnit->has((int) $unit->id))->values();

        $championsByUnit = KriDepartmentContact::query()
            ->where('role', KriContactRole::RiskChampion->value)
            ->whereIn('business_unit_id', $units->pluck('id')->all())
            ->with('user')
            ->get()
            ->groupBy('business_unit_id');

        foreach ($units as $unit) {
            $champions = $championsByUnit->get($unit->id, collect());
            $report['departments'][] = [
                'id' => $unit->id,
                'name' => $unit->name,
                'kris_due' => $dueByUnit->get((int) $unit->id)->count(),
                'champions' => $champions->map(fn ($c) => $c->user?->name)->filter()->values()->all(),
            ];

            if ($champions->isEmpty()) {
                $report['departments_without_champion']++;
            }
        }

        $report['departments_due'] = $units->count();

        return [$units, $dueByUnit];
    }

    /**
     * One department: the return, its lines and its single request notice.
     *
     * @param  Collection<int, array{kri: KeyRiskIndicator, period: Period}>  $due
     * @return array{opened: bool, request_queued: bool, request_redispatched: bool, dispatch_failed: bool, has_champion: bool}
     */
    private function openReturn(BusinessUnit $unit, Period $period, CarbonImmutable $day, KriCycleRun $run, Collection $due): array
    {
        $noticeId = null;
        $redispatchId = null;
        $opened = false;

        DB::transaction(function () use ($unit, $period, $day, $run, $due, &$noticeId, &$redispatchId, &$opened) {
            $return = $this->firstOrCreateReturn($unit, $period, $day, $run);
            $opened = $return->wasRecentlyCreated;

            if ($opened) {
                AuditTrailService::record($return, 'return_opened', 'status', null, KriReturnStatus::Draft->value);
            }

            $return->setRelation('period', $period);
            $this->syncLines($return, $due);

            $noticeId = $this->notices->queueRequest($return, $day);

            if ($noticeId === null) {
                // A re-run (forced, or a second server) of a return whose
                // request row exists. If that row never left `queued` - the
                // first run died, or Redis was down, between commit and
                // dispatch - this is where it gets back onto the queue. Safe:
                // DispatchKriReturnNotice acts only on a still-`queued` notice
                // under a row lock, so a duplicate job resolves nothing twice.
                // It is NEVER a second request: --force does not mean resend.
                $existing = $this->requestNoticeFor($return);

                if ($existing !== null
                    && $existing->status === KriReturnNotice::STATUS_QUEUED
                    && $existing->recipients_resolved_at === null) {
                    $redispatchId = (int) $existing->id;
                }
            }
        });

        // Outside the transaction, so it is after commit. dispatchNotice()
        // never throws: the notice is persisted and the watchdog owns it now.
        $dispatchFailed = false;

        if ($noticeId !== null) {
            $dispatchFailed = ! $this->notices->dispatchNotice($noticeId);
        } elseif ($redispatchId !== null) {
            $dispatchFailed = ! $this->notices->dispatchNotice($redispatchId);
        }

        $hasChampion = KriDepartmentContact::query()
            ->where('business_unit_id', $unit->id)
            ->where('role', KriContactRole::RiskChampion->value)
            ->exists();

        return [
            'opened' => $opened,
            'request_queued' => $noticeId !== null,
            'request_redispatched' => $redispatchId !== null && ! $dispatchFailed,
            'dispatch_failed' => $dispatchFailed,
            'has_champion' => $hasChampion,
        ];
    }

    /**
     * The department's return for the period, locked for the rest of the
     * caller's transaction.
     *
     * THE LOCK. A forced re-run mid-month syncs lines on a return a champion
     * may be saving at that moment. KriReturnService locks this row before its
     * own syncLines(); without the same lock here both could insert the same
     * line and one would die on kri_return_lines_return_kri_unique.
     *
     * THE RACE. Two servers both miss the row and both INSERT. InnoDB makes the
     * loser wait on the winner's key and then raises 1062. The loser's
     * transaction already took its REPEATABLE READ snapshot at the first plain
     * SELECT, which was BEFORE the winner committed, so a plain re-read returns
     * NULL on MariaDB 10.4 and MySQL 8 alike. The re-read is therefore a
     * locking read, which reads the latest committed version.
     */
    private function firstOrCreateReturn(BusinessUnit $unit, Period $period, CarbonImmutable $day, KriCycleRun $run): KriReturn
    {
        $existing = KriReturn::query()
            ->where('business_unit_id', $unit->id)
            ->where('period_id', $period->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return KriReturn::query()->create([
                'organization_id' => $unit->organization_id,
                'business_unit_id' => $unit->id,
                'period_id' => $period->id,
                'kri_cycle_run_id' => $run->id,
                'status' => KriReturnStatus::Draft->value,
                'requested_on' => $day->toDateString(),
                'due_on' => $this->calendar->addWorkingDays($day, KriWorkingCalendar::DUE_WORKING_DAYS, (int) $unit->organization_id)->toDateString(),
                'opened_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Another server won the race on the unique key: use its row.
            $winner = KriReturn::query()
                ->where('business_unit_id', $unit->id)
                ->where('period_id', $period->id)
                ->lockForUpdate()
                ->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    private function startRun(int $organizationId, Period $period, CarbonImmutable $day, bool $force, string $trigger, ?int $triggeredBy): KriCycleRun
    {
        $run = KriCycleRun::query()->where('period_id', $period->id)->first();

        if ($run === null) {
            try {
                return KriCycleRun::query()->create([
                    'organization_id' => $organizationId,
                    'period_id' => $period->id,
                    'run_on' => $day->toDateString(),
                    'trigger' => $trigger,
                    'forced' => $force,
                    'status' => KriCycleRun::STATUS_RUNNING,
                    'started_at' => now(),
                    'triggered_by' => $triggeredBy,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Autocommit, so this read is a fresh snapshot and sees the winner.
                $run = KriCycleRun::query()->where('period_id', $period->id)->first() ?? throw $e;
            }
        }

        // A re-run reuses the row and does not add one.
        $run->forceFill([
            'status' => KriCycleRun::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
            'trigger' => $trigger,
            'forced' => $run->forced || $force,
            'triggered_by' => $triggeredBy ?? $run->triggered_by,
        ])->save();

        return $run;
    }

    /** The notice a return carries for its request, if any (used by tests and the drill-down). */
    public function requestNoticeFor(KriReturn $return): ?KriReturnNotice
    {
        return KriReturnNotice::query()
            ->where('kri_return_id', $return->id)
            ->where('kind', KriReturnNotice::KIND_REQUEST)
            ->first();
    }
}
