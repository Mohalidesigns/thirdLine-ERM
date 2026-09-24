<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\RaiseBcmsGapAnalysisFindingRequest;
use App\Services\Bcms\Compliance\ClauseComplianceMatrixService;
use App\Services\Bcms\Compliance\GapAnalyserService;
use App\Services\Bcms\Findings\FindingService;
use App\Services\Bcms\ResilienceKriPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Compliance & Evidence screen — the examiner-facing clause matrix.
 *
 * A PURE READ SURFACE. Nothing here writes to a clause, an obligation, or an
 * artefact — the matrix aggregates and displays; the producing screen
 * registers (`docs/bcms/screens/compliance-evidence-matrix.md` §4).
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly ClauseComplianceMatrixService $matrix,
        private readonly ResilienceKriPublisher $kris,
        private readonly GapAnalyserService $gapAnalyser,
        private readonly FindingService $findings,
    ) {}

    public function matrix(): Response
    {
        Gate::authorize('bcms.report.view');

        return Inertia::render('Bcms/Compliance/Matrix', $this->matrix->build() + [
            'can' => [
                'export' => request()->user()?->can('bcms.report.export') ?? false,
            ],
        ]);
    }

    public function resilienceKris(): JsonResponse
    {
        Gate::authorize('bcms.report.view');

        return response()->json(['kris' => $this->kris->status()]);
    }

    public function maturityHeatmap(): JsonResponse
    {
        Gate::authorize('bcms.report.view');

        // A view over the Phase 1 scoring engine only — no second scorer
        // (criterion 11). The per-branch dimension does not exist in the
        // schema today (`bcms_maturity_assessments.organization_id`, no
        // business-unit column), and this endpoint says so rather than
        // fabricating branch rows.
        $assessment = app(\App\Services\Bcms\MaturityService::class)->latest();

        return response()->json([
            'assessment' => $assessment === null ? null : [
                'assessed_at' => $assessment->assessed_at?->toIso8601String(),
                'overall_score' => $assessment->overall_score === null ? null : (float) $assessment->overall_score,
                'method_version' => $assessment->method_version,
            ],
            'clause_groups' => $assessment === null ? [] : $assessment->scores()->get()->map(fn ($s) => [
                'clause_group' => $s->clause_group,
                'score' => $s->score,
                'rationale' => $s->rationale,
            ])->all(),
            'per_branch_available' => false,
            'per_branch_note' => 'This build scores one organisation at a time; a per-branch heatmap is not buildable honestly today.',
        ]);
    }

    public function gapAnalysis(): JsonResponse
    {
        Gate::authorize('bcms.report.view');

        return response()->json(['findings' => $this->gapAnalyser->draftFindings()]);
    }

    /**
     * Raise a gap-analyser citation as a finding — reuses the existing
     * findings register, `source = gap_analysis`, matching
     * `Findings/Index.jsx`'s own raise form rather than a new endpoint (this
     * route delegates to the existing `bcms.findings.store` handler's
     * authority and shape; see `FindingController::store()`).
     *
     * `RaiseBcmsGapAnalysisFindingRequest` validates `clause_ref` against the
     * CURRENT mandatory red/amber set (B8, gate 1 code review #1) and
     * carries the `bcms.finding.manage` authorisation check itself, so
     * `Gate::authorize()` is not repeated here.
     */
    public function raiseFromGapAnalysis(RaiseBcmsGapAnalysisFindingRequest $request): RedirectResponse
    {
        $this->findings->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Improvement,
            (string) $request->validated('finding'),
            null,
            [
                'organization_id' => $request->user()->organization_id,
                'iso_clause_ref' => $request->validated('clause_ref'),
                // B8: a deterministic, templated citation over computed
                // matrix rows — never an LLM call. See GapAnalyserService's
                // own docblock.
                'ai_generated' => false,
            ],
            $request->user()->getKey(),
        );

        return back()->with('success', 'Finding raised from the gap analyser.');
    }
}
