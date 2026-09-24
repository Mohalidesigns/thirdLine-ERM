<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\DrStrategy;
use App\Enums\Bcms\DrTestType;
use App\Models\Bcms\Application;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Services\Bcms\Dr\DrService;
use Illuminate\Support\Facades\Auth;

/**
 * What the DR system register and the DR test record draw. Standard §1: the
 * controller resolves and authorises; every computed fact — the tier-mismatch
 * sentence, the summary line, the unpaired-failover banner — is shaped here.
 */
class DrPresenter
{
    public function __construct(private DrService $dr) {}

    /** @return array<string, mixed> */
    public function register(?string $search = null, bool $mismatchesOnly = false, bool $overdueOnly = false): array
    {
        $orgId = Auth::user()?->organization_id;

        $query = DrSystem::query()->where('organization_id', $orgId)->with('application:id,name,is_active');

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('application', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($overdueOnly) {
            $query->whereNotNull('next_test_due')->where('next_test_due', '<', now()->toDateString());
        }

        $systems = $query->orderBy('name')->get();

        // Gate 2 review #1 defect 11. This used to call tierMismatch() once
        // per row (2 queries each), then tierMismatches() again below to
        // build the summary count — re-fetching every system in the org and
        // repeating the same 2 queries a second time — and overdue() a third
        // time for one more count. tierMismatchesForSystems() does the one
        // dependency lookup and one BIA lookup the whole set needs, reused
        // for both the per-row column and the summary counts below, so the
        // query count no longer grows with the number of systems on the
        // register.
        $mismatchesById = $this->dr->tierMismatchesForSystems($systems);

        $rows = $systems->map(function (DrSystem $s) use ($mismatchesById) {
            $mismatch = $mismatchesById[$s->getKey()] ?? null;

            return [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'application' => $s->application?->name,
                'application_retired' => $s->application !== null && ! $s->application->is_active,
                'recovery_tier' => $s->recovery_tier,
                'rto_target_hours' => $s->rto_target_hours,
                'rpo_target_minutes' => $s->rpo_target_minutes,
                'dr_strategy' => $s->dr_strategy?->value,
                'last_test_date' => $s->last_test_date?->toDateString(),
                'last_test_rto_actual_minutes' => $s->last_test_rto_actual_minutes,
                'last_test_met_objectives' => $s->last_test_met_objectives,
                'next_test_due' => $s->next_test_due?->toDateString(),
                'is_overdue' => $s->next_test_due !== null && $s->next_test_due->isPast(),
                'backup_verified_at' => $s->last_backup_verified_at?->toIso8601String(),
                'backup_stale' => $s->last_backup_verified_at === null
                    || $s->last_backup_verified_at->lt(now()->subDays((int) config('bcms.dr.backup_currency_days', 30))),
                'has_no_runbook' => $s->failover_runbook_plan_id === null,
                'tier_mismatch' => $mismatch === null ? null : sprintf(
                    '%s-hour RTO needed by %s vs %s-hour tier target',
                    $mismatch['required_hours'], $mismatch['process'], $mismatch['target_hours'],
                ),
                'tests_url' => route('bcms.dr-systems.tests.index', $s),
                'backup_attestation_url' => route('bcms.dr-systems.backup-attestation', $s),
                'update_url' => route('bcms.dr-systems.update', $s),
            ];
        });

        if ($mismatchesOnly) {
            $rows = $rows->filter(fn (array $r) => $r['tier_mismatch'] !== null)->values();
        }

        $user = Auth::user();

        return [
            'systems' => $rows->all(),
            'filters' => ['search' => $search, 'mismatches_only' => $mismatchesOnly, 'overdue_only' => $overdueOnly],
            'summary' => [
                'total' => $systems->count(),
                // Computed from the same $mismatchesById map the rows above
                // already built — no second fetch of every system in the
                // org. This does mean the count now reflects the current
                // search/overdue-only filter, the same way 'total' and
                // 'backup_stale' already did; previously it silently did
                // not, which was never the reason defect 11 was raised.
                'mismatches' => collect($mismatchesById)->filter(fn (?array $m) => $m !== null)->count(),
                'overdue' => $systems->filter(
                    fn (DrSystem $s) => $s->next_test_due !== null && $s->next_test_due->isPast()
                )->count(),
                'backup_stale' => $rows->where('backup_stale', true)->count(),
            ],
            'can' => ['manage' => $user?->can('bcms.dr.manage') === true],
            'options' => [
                'applications' => Application::query()->where('organization_id', $orgId)
                    ->orderBy('name')->get(['id', 'name', 'is_active'])
                    ->map(fn (Application $a) => ['id' => $a->getKey(), 'name' => $a->name, 'is_active' => (bool) $a->is_active])->all(),
                'sites' => Site::query()->where('organization_id', $orgId)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Site $s) => ['id' => $s->getKey(), 'name' => $s->name])->all(),
                'plans' => Plan::query()->where('organization_id', $orgId)->where('status', 'approved')
                    ->orderBy('title')->get(['id', 'title'])
                    ->map(fn (Plan $p) => ['id' => $p->getKey(), 'title' => $p->title])->all(),
                'dr_strategies' => collect(DrStrategy::cases())->map(fn (DrStrategy $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            ],
            'store_url' => route('bcms.dr-systems.store'),
        ];
    }

    /** @return array<string, mixed> */
    public function testHistory(DrSystem $system): array
    {
        $tests = $system->tests()->orderByDesc('test_date')->get();

        $lastFailover = $tests->firstWhere('test_type', \App\Enums\Bcms\DrTestType::Failover);
        $hasLaterFailback = $lastFailover !== null && $tests->contains(
            fn (DrTest $t) => $t->test_type === \App\Enums\Bcms\DrTestType::Failback
                && $t->test_date->gte($lastFailover->test_date)
        );

        $user = Auth::user();

        return [
            'system' => [
                'uuid' => $system->uuid, 'name' => $system->name,
                'rto_target_hours' => $system->rto_target_hours,
                'rpo_target_minutes' => $system->rpo_target_minutes,
            ],
            'tests' => $tests->map(fn (DrTest $t) => [
                'id' => $t->getKey(),
                'test_date' => $t->test_date->toDateString(),
                'test_type' => $t->test_type->value,
                'test_type_label' => $t->test_type->label(),
                'rto_actual_minutes' => $t->rto_actual_minutes,
                'rpo_actual_minutes' => $t->rpo_actual_minutes,
                'met_objectives' => $t->met_objectives,
                'rollback_required' => (bool) $t->rollback_required,
                'source' => ($t->evidence['provider'] ?? null) !== null ? 'Ingested from '.$t->evidence['provider'] : 'Recorded manually',
                'show_url' => route('bcms.dr-tests.show', $t),
            ])->values()->all(),
            'unpaired_failover' => $lastFailover !== null && ! $hasLaterFailback,
            'test_types' => collect(DrTestType::cases())->map(fn (DrTestType $t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'can' => ['record' => $user?->can('bcms.dr.test.record') === true],
            'store_url' => route('bcms.dr-systems.tests.store', $system),
            'register_url' => route('bcms.dr-systems.index'),
        ];
    }

    /** @return array<string, mixed> */
    public function testShow(DrTest $test): array
    {
        $test->loadMissing('drSystem:id,uuid,name,rto_target_hours,rpo_target_minutes,application_id');

        $user = Auth::user();
        $breached = $test->met_objectives === false;

        // Review #2 defect 12, clause map §6 item 6: criterion 2's "flags
        // the dependent BIA assessments" is one finding PER dependent
        // process (`Finding.affected_process_id` is a single FK, so several
        // processes cannot be named on one row) plus `iso22301.8.2.2` —
        // computed here, server-side, rather than left for the raise-a-
        // finding form to guess at, and only when there is a breach to
        // raise a finding about at all.
        $affectedProcesses = $breached && $test->drSystem !== null
            ? $this->dr->dependentProcesses($test->drSystem)
            : collect();

        return [
            'test' => [
                'id' => $test->getKey(),
                'test_date' => $test->test_date->toDateString(),
                'test_type' => $test->test_type->value,
                'test_type_label' => $test->test_type->label(),
                'rto_target_hours' => $test->drSystem?->rto_target_hours,
                'rto_actual_minutes' => $test->rto_actual_minutes,
                'rpo_target_minutes' => $test->drSystem?->rpo_target_minutes,
                'rpo_actual_minutes' => $test->rpo_actual_minutes,
                'met_objectives' => $test->met_objectives,
                'rollback_required' => (bool) $test->rollback_required,
                'issues' => $test->issues,
                'notes' => $test->notes,
                'evidence' => $test->evidence,
                'is_ingested' => ($test->evidence['provider'] ?? null) !== null,
                'pending_confirmation' => ($test->evidence['provider'] ?? null) !== null && $test->met_objectives === null,
            ],
            'system' => ['uuid' => $test->drSystem?->uuid, 'name' => $test->drSystem?->name],
            'can' => [
                'record' => $user?->can('bcms.dr.test.record') === true,
                'raise_finding' => $breached && $user?->can('bcms.finding.manage') === true,
            ],
            'affected_processes' => $affectedProcesses->map(fn (Process $p) => [
                'id' => $p->getKey(), 'code' => $p->code, 'name' => $p->name,
            ])->values()->all(),
            'finding_iso_clause_ref' => \App\Enums\Bcms\IsoClauseRef::Iso22301_8_2_2->value,
            'confirm_url' => route('bcms.dr-tests.confirm-objectives', $test),
            'raise_finding_url' => route('bcms.findings.store'),
            'tests_index_url' => $test->drSystem !== null ? route('bcms.dr-systems.tests.index', $test->drSystem) : null,
        ];
    }
}
