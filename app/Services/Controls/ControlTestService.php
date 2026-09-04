<?php

namespace App\Services\Controls;

use App\Models\Control;
use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\NotificationService;
use App\Services\ReferenceCodeService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

/**
 * Control testing (migration Phase 3.4).
 *
 * Lifted out of ControlTestController: the dashboard's six figures were
 * computed in the action where no test could reach them, and completing a test
 * writes the result, rolls the control's testing statistics forward and hands
 * the decision to the workflow engine — three objects in one call.
 *
 * The review decision itself is NOT here. It lives in ControlTestBinding, so a
 * review recorded from My Tasks does exactly what one recorded on the test's
 * own page does; this only routes to it.
 */
class ControlTestService
{
    public function __construct(private FileUploadService $uploads) {}

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * The testing dashboard's figures.
     *
     * Every count is a plain count per status and the pass rate is
     * effective-over-completed to one decimal, both pinned by
     * tests/Feature/Characterisation/ControlTestingDashboardTest. `passRate`
     * is 0 rather than null when nothing is completed — carried unchanged,
     * because unlike a KPI tile standing in for an unmeasured figure this one
     * IS measured: zero of zero completed tests passed.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $orgId = TenantContext::organizationId();
        $scoped = fn () => ControlTest::where('organization_id', $orgId);

        $completedTests = $scoped()->where('status', 'completed')->count();

        return [
            'totalTests' => $scoped()->count(),
            'scheduledTests' => $scoped()->where('status', 'scheduled')->count(),
            'inProgress' => $scoped()->where('status', 'in_progress')->count(),
            'completedTests' => $completedTests,
            'overdueTests' => $scoped()
                ->where('status', 'scheduled')
                ->where('scheduled_date', '<', now())
                ->count(),
            'passRate' => $completedTests > 0
                ? round($scoped()->where('status', 'completed')->where('result', 'effective')->count() / $completedTests * 100, 1)
                : 0,
            'recentTests' => $this->summarise(
                $scoped()->with(['control', 'tester'])->latest('updated_at')->take(10)->get()
            ),
            'upcomingTests' => $this->summarise(
                $scoped()->where('status', 'scheduled')->with(['control', 'tester'])
                    ->orderBy('scheduled_date')->take(10)->get()
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Read side */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function formOptions(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'controls' => Control::where('organization_id', $orgId)
                ->visibleTo()
                ->orderBy('name')
                ->get(['id', 'control_code', 'name'])
                ->map(fn (Control $control) => [
                    'id' => $control->id,
                    'control_code' => $control->control_code,
                    'name' => $control->name,
                ])->values()->all(),
            'users' => User::where('organization_id', $orgId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values()->all(),
            'options' => [
                'types' => ControlTest::TYPES,
                'results' => ControlTest::RESULTS,
                'statuses' => ControlTest::STATUSES,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ControlTest $test): array
    {
        $test->load(['control.risks', 'tester', 'reviewer', 'evidence', 'creator']);

        return [
            'test' => $this->present($test),
            'control' => [
                'id' => data_get($test, 'control.id'),
                'control_code' => data_get($test, 'control.control_code'),
                'name' => data_get($test, 'control.name'),
                'control_type' => data_get($test, 'control.control_type'),
                'effectiveness_rating' => data_get($test, 'control.effectiveness_rating'),
            ],
            'linkedRisks' => ($test->control->risks ?? collect())
                ->map(fn ($risk) => [
                    'id' => $risk->id,
                    'risk_code' => $risk->risk_code,
                    'title' => $risk->title,
                ])->values()->all(),
            'evidence' => $test->evidence->map(fn (ControlTestEvidence $item) => [
                'id' => $item->getKey(),
                'file_name' => data_get($item, 'file_name'),
                'file_type' => data_get($item, 'file_type'),
                'file_size' => data_get($item, 'file_size'),
                'description' => data_get($item, 'description'),
                'uploaded_at' => optional(data_get($item, 'created_at'))->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(ControlTest $test): array
    {
        return [
            'id' => $test->getKey(),
            'test_code' => $test->test_code ?? 'CT-'.$test->getKey(),
            'control_id' => $test->control_id,
            'title' => $test->title,
            'description' => $test->description,
            'test_type' => $test->test_type,
            'status' => $test->status,
            'result' => $test->result,
            'score' => $test->score,
            'findings' => $test->findings,
            'recommendations' => $test->recommendations,
            'reviewer_notes' => $test->reviewer_notes,
            'tester_id' => $test->tester_id,
            'tester' => $test->tester?->name,
            'reviewer_id' => $test->reviewer_id,
            'reviewer' => $test->reviewer?->name,
            'creator' => $test->creator?->name,
            'scheduled_date' => optional($test->scheduled_date)->toDateString(),
            'started_date' => optional($test->started_date)->toDateString(),
            'completed_date' => optional($test->completed_date)->toDateString(),
            'reviewed_at' => optional($test->reviewed_at)->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Write side */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated  StoreControlTestRequest::validated()
     */
    public function create(array $validated, int $organizationId, ?int $userId): ControlTest
    {
        return ControlTest::create([
            'organization_id' => $organizationId,
            'control_id' => $validated['control_id'],
            'test_code' => ReferenceCodeService::generate('control_tests', 'test_code', 'CT'),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'test_type' => $validated['test_type'],
            'tester_id' => $validated['tester_id'],
            'reviewer_id' => $validated['reviewer_id'] ?? null,
            'scheduled_date' => $validated['scheduled_date'],
            'status' => 'scheduled',
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated  UpdateControlTestRequest::validated()
     */
    public function update(ControlTest $test, array $validated): ControlTest
    {
        $test->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'test_type' => $validated['test_type'],
            'tester_id' => $validated['tester_id'] ?? $test->tester_id,
            'reviewer_id' => $validated['reviewer_id'] ?? null,
            'scheduled_date' => $validated['scheduled_date'],
        ]);

        return $test;
    }

    public function start(ControlTest $test): ControlTest
    {
        $test->update(['status' => 'in_progress', 'started_date' => now()]);

        return $test;
    }

    /**
     * Record a result and hand the decision on.
     *
     * A test with no reviewer is complete on submission — there is nothing to
     * approve, so no workflow is started. That is the same rule the status
     * line encodes; the engine does not change it.
     *
     * @param  array<string, mixed>  $validated  ExecuteControlTestRequest::validated()
     */
    public function complete(ControlTest $test, array $validated, ?User $actor, ModuleApprovals $approvals): ControlTest
    {
        $test->update([
            'result' => $validated['result'],
            'findings' => $validated['findings'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'score' => $validated['score'] ?? null,
            'completed_date' => now(),
            'status' => $test->reviewer_id ? 'pending_review' : 'completed',
        ]);

        $test->control->updateTestStats();

        if ($test->reviewer_id && ! $approvals->submit('control_test_review', $test, [], $actor)) {
            // No published definition for this tenant yet: tell the reviewer
            // directly, exactly as the approval queue used to.
            NotificationService::send(
                $test->organization_id,
                $test->reviewer_id,
                'approval_request',
                'Control test awaiting review: '.($test->test_code ?? 'CT-'.$test->id),
                'A control test has been submitted for your review.',
                ['entity_type' => $test->getMorphClass(), 'entity_id' => $test->id],
            );
        }

        return $test;
    }

    /**
     * Route a review decision through the workflow engine, falling back to a
     * direct decision where the tenant has published no definition.
     *
     * @param  array<string, mixed>  $validated  ReviewControlTestRequest::validated()
     */
    public function review(ControlTest $test, array $validated, ?User $actor, ModuleApprovals $approvals): bool
    {
        $approve = $validated['action'] === 'approve';

        $comments = $approve
            ? ($validated['reviewer_notes'] ?? null)
            : ($validated['rejection_reason'] ?? $validated['reviewer_notes'] ?? null);

        // The status change, the reviewer stamp and the control's rolling
        // effectiveness recalculation all live in ControlTestBinding, so a
        // review recorded from My Tasks does exactly what one recorded here does.
        if (! $approvals->decide($test, $approve ? 'approve' : 'reject', $actor, ['comments' => $comments])) {
            $approvals->decideDirectly($test, $approve ? 'approve' : 'reject', $actor, $comments);
        }

        return $approve;
    }

    /**
     * Tester re-submits a rejected test after rework: rejected -> in_progress,
     * so they can record a result again.
     */
    public function resubmit(ControlTest $test): ControlTest
    {
        $test->update([
            'status' => 'in_progress',
            // `control_tests.result` is a NOT NULL enum whose "no result yet"
            // value is `not_tested`. The controller cleared it to null, which
            // is a constraint violation — so resubmitting a rejected test was
            // a 500 and had never worked.
            'result' => 'not_tested',
            'reviewer_notes' => null,
            'reviewed_at' => null,
        ]);

        return $test;
    }

    /**
     * Store a piece of evidence.
     *
     * WP-11: through FileUploadService, which can only write to the private
     * disk, and with `file_type` derived from the bytes rather than from the
     * tail of a client-supplied filename.
     */
    public function attachEvidence(ControlTest $test, UploadedFile $file, ?string $description, ?int $userId): ControlTestEvidence
    {
        $stored = $this->uploads->store(
            $file,
            'control-test-evidence/'.$test->id,
            FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE,
        );

        return ControlTestEvidence::create([
            'control_test_id' => $test->id,
            'file_name' => $stored['file_name'],
            'file_path' => $stored['storage_path'],
            'file_type' => $stored['file_type'],
            'file_size' => $stored['file_size_bytes'],
            'description' => $description,
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ControlTest>  $tests
     * @return list<array<string, mixed>>
     */
    private function summarise($tests): array
    {
        return $tests->map(fn (ControlTest $test) => [
            'id' => $test->getKey(),
            'test_code' => $test->test_code ?? 'CT-'.$test->getKey(),
            'title' => $test->title,
            'status' => $test->status,
            'result' => $test->result,
            'scheduled_date' => optional($test->scheduled_date)->toDateString(),
            'control_code' => data_get($test, 'control.control_code'),
            'control_name' => data_get($test, 'control.name'),
            'tester' => data_get($test, 'tester.name'),
        ])->values()->all();
    }
}
