<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\EnforcesNodeScope;
use App\Http\Controllers\Controller;
use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\RiskCause;
use App\Models\RiskCauseCategory;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\AssessmentChainService;
use App\Services\NotificationService;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Authorization\GraphScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The risk assessment journey.
 *
 * WP-10a rebuilt this around the chain a risk expert's review set as canonical:
 *
 *   Risk → Root Cause → Likelihood → Impact → Inherent Risk →
 *   Existing Controls → Control Effectiveness → Residual Risk →
 *   Risk Treatment → Action Plan → Owner → Due Date → KRI
 *
 * What this screen used to be: likelihood, impact, and two free-text boxes for
 * a residual likelihood and impact the assessor typed in. Root cause had no
 * home anywhere in the product. Controls were never shown, so the residual
 * score bore no arithmetic relationship to the controls the risk actually had.
 * Treatment, owner, due date and KRI were four other modules the assessor had
 * to remember to visit afterwards — and, judging by the register, usually
 * didn't.
 *
 * Each step now writes to the object that owns it: causes to `risk_causes`,
 * control ratings to `risk_assessment_controls`, actions to `treatment_plans`,
 * monitoring to `key_risk_indicators`. The assessment is the journey through
 * them, not a duplicate store of their data.
 */
class RiskAssessmentController extends Controller
{
    // WP-00 node scoping: an assessment inherits its risk's visibility.
    use EnforcesNodeScope;

    public function __construct(
        private RiskScoringService $scoring,
        private AssessmentChainService $chain,
    ) {}

    /**
     * List all assessments. Search, filters, sorting and pagination all
     * moved into the shared data grid (WP-09) — see
     * App\Grids\Definitions\RiskAssessmentsGrid.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        // WP-00: scoped through the risk, matching RiskAssessmentsGrid.
        $total = GraphScope::applyThrough(
            RiskAssessment::where('organization_id', TenantContext::organizationId()),
            'risk'
        )->count();

        return Inertia::render('Assessments/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('assessments'), $request, $request->user()),
        ]);
    }

    /**
     * Show the assessment form.
     *
     * Step 1 — the risk — is chosen before the rest of the chain can be drawn:
     * which controls to rate and which causes to review are properties of the
     * risk, so without one there is no form to render. A request with no
     * `risk_id` therefore gets the risk picker rather than a form full of empty
     * selects.
     */
    public function create(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $risk = $request->filled('risk_id')
            ? Risk::where('organization_id', $orgId)->find($request->integer('risk_id'))
            : null;

        if ($risk === null) {
            return view('risk.assessments.select-risk', [
                'risks' => Risk::where('organization_id', $orgId)
                    ->where('status', 'active')
                    ->with('category')
                    ->orderBy('risk_code')
                    ->get(),
            ]);
        }

        return view('risk.assessments.create', $this->formData($risk));
    }

    /**
     * Everything the 13-step form needs, for a new assessment or a draft being
     * edited. One method, so the create and edit forms cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function formData(Risk $risk, ?RiskAssessment $assessment = null): array
    {
        $orgId = $risk->organization_id;
        $profile = $this->scoring->profileForRisk($risk);

        return [
            'risk' => $risk,
            'assessment' => $assessment,
            'profile' => $profile,

            // Step 2
            'causes' => $risk->causes()->with('category')->get(),
            'causeCategories' => RiskCauseCategory::options(),
            'causeSources' => RiskCause::SOURCES,

            // Steps 3-5: the axes and dimensions come from the organization's
            // scoring profile, not from a hardcoded 5×5 — which is also how
            // `impact_people` stops being offered on a form that has nowhere to
            // store it.
            'likelihoodLabels' => $profile->axisLabels('likelihood'),
            'impactLabels' => $profile->axisLabels('impact'),
            'dimensions' => $profile->dimensions(),

            // The same configuration the server scores with, handed to the
            // browser so the running totals on the form match what saving
            // produces. The form is explicit that they are a preview and that
            // the server recomputes — a tenant on a custom residual formula
            // gets an approximation here and the real number on save.
            'scoringConfig' => [
                'rows' => $profile->matrix_rows,
                'cols' => $profile->matrix_cols,
                'aggregation' => $profile->impact_aggregation ?? 'max',
                'weights' => $profile->weights(),
                'bands' => $profile->rating_bands ?? [],
                'effectiveness' => \App\Support\RiskCalculationSettings::effectivenessMap($orgId),
            ],

            // Steps 6-8
            'controls' => $this->chain->controlsFor($risk, $assessment),
            'effectivenessRatings' => RiskAssessmentControl::RATINGS,

            // Step 9
            'strategies' => RiskAssessment::TREATMENT_STRATEGIES,

            // Steps 10-12
            'actionPlans' => $assessment
                ? TreatmentPlan::where('risk_id', $risk->id)->orderByDesc('id')->get()
                : collect(),
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(),

            // Step 13
            'linkedKris' => KeyRiskIndicator::where('organization_id', $orgId)
                ->where('risk_id', $risk->id)->orderBy('kri_code')->get(),
            'availableKris' => KeyRiskIndicator::where('organization_id', $orgId)
                ->whereNull('risk_id')->orderBy('kri_code')->get(),

            'previousAssessment' => RiskAssessment::where('risk_id', $risk->id)
                ->where('status', 'approved')
                ->when($assessment?->exists, fn ($query) => $query->where('id', '!=', $assessment->id))
                ->orderByDesc('assessment_date')
                ->first(),
        ];
    }

    /**
     * Store a new assessment — the whole chain in one transaction.
     */
    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $risk = Risk::where('organization_id', $orgId)
            ->findOrFail($request->integer('risk_id'));

        $validated = $this->validateChain($request, $risk);

        return DB::transaction(function () use ($validated, $request, $risk) {
            $assessment = new RiskAssessment([
                'risk_id' => $risk->id,
                'assessor_id' => auth()->id(),
                'status' => 'draft',
            ]);

            $this->persistChain($assessment, $validated, $risk, $request);

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', $request->input('action') === 'submit'
                    ? 'Risk assessment submitted for review.'
                    : 'Risk assessment saved as a draft.');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  The chain */
    /* ------------------------------------------------------------------ */

    /**
     * Validate all thirteen steps.
     *
     * Axis bounds come from the organization's scoring profile rather than a
     * hardcoded 1–5, so a tenant on a 4×4 or 10×10 matrix is validated against
     * the grid they actually use.
     *
     * @return array<string, mixed>
     */
    private function validateChain(Request $request, Risk $risk): array
    {
        $profile = $this->scoring->profileForRisk($risk);
        $rows = $profile->matrix_rows;
        $cols = $profile->matrix_cols;
        $dimensions = $profile->dimensions();

        $rules = [
            'assessment_type' => 'required|in:initial,periodic,event_driven,triggered,annual,full,targeted',
            'assessment_date' => 'required|date',

            // Step 2 — Root Cause
            'causes' => 'array|max:50',
            'causes.*.id' => 'nullable|integer',
            'causes.*.description' => 'required_with:causes.*.cause_category_id|nullable|string|max:2000',
            'causes.*.cause_category_id' => 'nullable|integer|exists:risk_cause_categories,id',
            'causes.*.source' => 'nullable|string|in:'.implode(',', array_keys(RiskCause::SOURCES)),
            'causes.*.is_primary' => 'nullable|boolean',

            // Step 3 — Likelihood
            'likelihood' => "required|integer|min:1|max:{$rows}",
            'likelihood_rationale' => 'nullable|string|max:2000',

            // Steps 6-7 — Existing Controls and their effectiveness
            'controls' => 'array|max:200',
            'controls.*.design_effectiveness' => 'nullable|string|in:'.implode(',', array_keys(RiskAssessmentControl::RATINGS)),
            'controls.*.operating_effectiveness' => 'nullable|string|in:'.implode(',', array_keys(RiskAssessmentControl::RATINGS)),
            'controls.*.notes' => 'nullable|string|max:2000',
            'controls.*.evidence_ref' => 'nullable|string|max:255',

            // Step 8 — Residual Risk. Supplied by the form as the derived
            // values; only a change to them counts as an override.
            'residual_likelihood' => "nullable|integer|min:1|max:{$rows}",
            'residual_impact' => "nullable|integer|min:1|max:{$cols}",
            'residual_justification' => 'nullable|string|max:2000',

            // Step 9 — Risk Treatment
            'treatment_strategy' => 'nullable|in:'.implode(',', array_keys(RiskAssessment::TREATMENT_STRATEGIES)),

            // Steps 10-12 — Action Plan, Owner, Due Date
            'actions' => 'array|max:25',
            'actions.*.action_title' => 'nullable|string|max:200',
            'actions.*.action_description' => 'nullable|string|max:5000',
            'actions.*.owner_id' => 'nullable|integer|exists:users,id',
            'actions.*.target_date' => 'nullable|date',
            'actions.*.priority' => 'nullable|in:low,medium,high,critical',

            // Step 13 — KRI
            'kri_ids' => 'array|max:25',
            'kri_ids.*' => 'integer|exists:key_risk_indicators,id',

            'rationale' => 'required|string|max:5000',
            'recommendations' => 'nullable|string|max:5000',
            'action' => 'nullable|in:draft,submit',
        ];

        // Step 4 — Impact, one rule per dimension the profile actually scores.
        foreach ($dimensions as $dimension) {
            $rules["impact_{$dimension}"] = "nullable|integer|min:1|max:{$cols}";
        }

        $validated = $request->validate($rules, [
            'likelihood.required' => 'Likelihood is required — it is step 3 of the assessment.',
            'rationale.required' => 'An assessment rationale is required.',
        ]);

        $this->validateChainIntegrity($request, $validated, $dimensions);

        return $validated;
    }

    /**
     * The rules that are about the chain holding together rather than about any
     * one field being well-formed.
     */
    private function validateChainIntegrity(Request $request, array $validated, array $dimensions): void
    {
        $errors = [];

        // Step 4: at least one impact dimension has to be scored, or there is
        // no inherent risk to speak of.
        $scored = collect($dimensions)->filter(fn (string $d) => ! empty($validated["impact_{$d}"]));

        if ($scored->isEmpty()) {
            $errors['impact_financial'] = 'Score at least one impact dimension.';
        }

        // Steps 10-12: an action plan is only an action plan once it has an
        // owner and a due date. A titled action with neither is a wish, and
        // letting it save is how a treatment register fills up with them.
        foreach ((array) $request->input('actions', []) as $index => $action) {
            if (blank($action['action_title'] ?? null)) {
                continue;
            }

            if (blank($action['owner_id'] ?? null)) {
                $errors["actions.{$index}.owner_id"] = 'An action plan needs an owner (step 11).';
            }

            if (blank($action['target_date'] ?? null)) {
                $errors["actions.{$index}.target_date"] = 'An action plan needs a due date (step 12).';
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * Write the chain. Shared by store() and update() so a draft and a new
     * assessment go through identical logic.
     */
    private function persistChain(
        RiskAssessment $assessment,
        array $validated,
        Risk $risk,
        Request $request,
    ): RiskAssessment {
        $profile = $this->scoring->profileForRisk($risk);

        // Steps 4-5 — Impact and Inherent Risk. Both computed: an inherent
        // score the assessor can type is an inherent score that stops agreeing
        // with the likelihood and impact printed beside it.
        $impacts = collect($profile->dimensions())
            ->mapWithKeys(fn (string $d) => [$d => $validated["impact_{$d}"] ?? null])
            ->all();

        $impactScore = $this->scoring->calculateImpact($impacts, $risk->organization_id);
        $inherentScore = $this->scoring->calculateScore((int) $validated['likelihood'], $impactScore, $profile);

        $notes = trim(
            ($validated['rationale'] ?? '')
            .(! empty($validated['recommendations']) ? "\n\nRecommendations:\n".$validated['recommendations'] : '')
        );

        $assessment->fill([
            'organization_id' => $risk->organization_id,
            'assessment_type' => $validated['assessment_type'],
            'assessment_date' => $validated['assessment_date'],
            'likelihood_score' => (int) $validated['likelihood'],
            'likelihood_rationale' => $validated['likelihood_rationale'] ?? null,
            'impact_financial' => $impacts['financial'] ?? null,
            'impact_operational' => $impacts['operational'] ?? null,
            'impact_reputational' => $impacts['reputational'] ?? null,
            'impact_regulatory' => $impacts['regulatory'] ?? null,
            'impact_strategic' => $impacts['strategic'] ?? null,
            'impact_score' => $impactScore,
            'overall_score' => $inherentScore,
            'overall_rating' => $this->scoring->calculateRating($inherentScore, $profile),
            'treatment_strategy' => $validated['treatment_strategy'] ?? null,
            'assessment_notes' => $notes ?: null,
        ]);

        $assessment->save();

        // Step 2 — Root Cause, on the risk, snapshotted onto the assessment.
        $causes = $this->syncCauses($risk, $validated['causes'] ?? []);
        $assessment->cause_snapshot = $causes->map->toSnapshot()->values()->all();

        // Steps 6-8 — Controls, effectiveness, and the residual that follows
        // from them.
        $this->chain->syncControls($assessment, $validated['controls'] ?? []);
        $this->chain->applyToAssessment($assessment, [
            'likelihood' => $validated['residual_likelihood'] ?? null,
            'impact' => $validated['residual_impact'] ?? null,
            'justification' => $validated['residual_justification'] ?? null,
        ]);

        // An override without a reason is not a judgement, it is an unexplained
        // number. Checked after the service has decided whether the posted
        // values actually differ from the derivation.
        if ($assessment->residualWasOverridden() && blank($assessment->residual_justification)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'residual_justification' => 'Overriding the derived residual score requires a justification.',
            ]);
        }

        // Steps 10-12 — Action Plan, Owner, Due Date.
        $this->syncActionPlans($risk, $assessment, $request->input('actions', []));

        // Step 13 — KRI.
        $this->syncKris($risk, $validated['kri_ids'] ?? []);

        if ($request->input('action') === 'submit' && $assessment->status === 'draft') {
            $assessment->status = 'in_review';
        }

        $assessment->save();

        return $assessment;
    }

    /**
     * Step 2 — persist the risk's causes and return the set this assessment
     * considered.
     *
     * Causes are not deleted here. A cause that an assessor stops listing this
     * quarter has not stopped being true, and silently dropping it would lose
     * the history that makes cross-register cause analysis worth having.
     * Retiring a cause is a deliberate action on the risk.
     *
     * @return \Illuminate\Support\Collection<int, RiskCause>
     */
    private function syncCauses(Risk $risk, array $input): \Illuminate\Support\Collection
    {
        $existing = $risk->causes()->get()->keyBy('id');
        $considered = collect();
        $order = 0;

        foreach ($input as $row) {
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $attributes = [
                'description' => $description,
                'cause_category_id' => $row['cause_category_id'] ?: null,
                'source' => $row['source'] ?: null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'sort_order' => $order++,
            ];

            // An id is only honoured if it is a cause of THIS risk, so a
            // forged id cannot edit another risk's cause register.
            $cause = $existing->get((int) ($row['id'] ?? 0));

            if ($cause !== null) {
                $cause->update($attributes);
            } else {
                $cause = $risk->causes()->create($attributes + [
                    'organization_id' => $risk->organization_id,
                    'created_by' => auth()->id(),
                ]);
            }

            $considered->push($cause->load('category'));
        }

        // Only one cause can be the primary one.
        if ($considered->where('is_primary', true)->count() > 1) {
            $keep = $considered->firstWhere('is_primary', true);
            $risk->causes()->where('id', '!=', $keep->id)->update(['is_primary' => false]);
            $considered->where('id', '!=', $keep->id)->each->setAttribute('is_primary', false);
        }

        return $considered;
    }

    /**
     * Steps 10-12 — the action plans this assessment raised, each with the
     * owner and due date the chain requires.
     *
     * These are real `treatment_plans` rows, not a copy held on the assessment:
     * the action register, the owner's My Responsibilities queue and the
     * overdue-actions widget all read that table, and an action that only
     * exists inside an assessment reaches none of them.
     */
    private function syncActionPlans(Risk $risk, RiskAssessment $assessment, array $input): void
    {
        foreach ($input as $row) {
            $title = trim((string) ($row['action_title'] ?? ''));

            if ($title === '' || ! empty($row['id'])) {
                continue;
            }

            TreatmentPlan::create([
                'organization_id' => $risk->organization_id,
                'risk_id' => $risk->id,
                'treatment_code' => ReferenceCodeService::generate('treatment_plans', 'treatment_code', 'TP'),
                'strategy' => $assessment->treatment_strategy,
                'action_title' => $title,
                'action_description' => $row['action_description'] ?? null,
                'owner_id' => (int) $row['owner_id'],
                'target_date' => $row['target_date'],
                'priority' => $row['priority'] ?? 'medium',
                'status' => 'not_started',
                'progress_pct' => 0,
                // The residual this action is expected to achieve — the third
                // value on a treatment chart, alongside inherent and current
                // residual.
                'expected_residual_likelihood' => $assessment->residual_likelihood,
                'expected_residual_impact' => $assessment->residual_impact,
                'created_by' => auth()->id(),
            ]);
        }
    }

    /**
     * Step 13 — attach the KRIs that will monitor this risk going forward.
     *
     * Only unattached KRIs can be claimed: `key_risk_indicators.risk_id` is the
     * canonical link, and reassigning an indicator another risk is already
     * monitoring would silently blank that risk's monitoring.
     */
    private function syncKris(Risk $risk, array $kriIds): void
    {
        if ($kriIds === []) {
            return;
        }

        KeyRiskIndicator::where('organization_id', $risk->organization_id)
            ->whereIn('id', $kriIds)
            ->where(fn ($query) => $query->whereNull('risk_id')->orWhere('risk_id', $risk->id))
            ->update(['risk_id' => $risk->id]);
    }

    /**
     * Display assessment detail with comparison to previous.
     */
    public function show(RiskAssessment $assessment)
    {
        $this->authorizeTenant($assessment);
        $orgId = $assessment->organization_id;

        $assessment->load([
            'risk.category', 'risk.riskOwner', 'assessor',
            // The chain, in order: causes, the controls as rated here, the
            // actions this assessment raised, and the KRIs now watching it.
            'risk.causes.category',
            'assessedControls.control',
        ]);

        $actionPlans = TreatmentPlan::where('risk_id', $assessment->risk_id)
            ->with('owner')
            ->orderBy('target_date')
            ->get();

        $kris = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('risk_id', $assessment->risk_id)
            ->orderBy('kri_code')
            ->get();

        $effectiveness = $this->chain->effectiveness($assessment->assessedControls);

        // Get previous assessment for comparison
        $previousAssessment = RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $orgId)
            ->where('assessment_date', '<', $assessment->assessment_date)
            ->where('status', 'approved')
            ->orderByDesc('assessment_date')
            ->first();

        // Full assessment history for this risk, with per-assessment score change
        // computed against the chronologically preceding assessment.
        $history = RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $orgId)
            ->with('assessor')
            ->orderBy('assessment_date')
            ->orderBy('id')
            ->get();

        $previousScore = null;
        foreach ($history as $item) {
            $item->setAttribute(
                'score_change',
                $previousScore === null ? 0 : ((int) $item->overall_score - $previousScore)
            );
            $previousScore = (int) $item->overall_score;
        }

        $assessmentHistory = $history->sortByDesc('assessment_date')->values();

        // Mirror the computed change onto the assessment shown in the KPI cards
        $currentInHistory = $history->firstWhere('id', $assessment->id);
        $assessment->setAttribute('score_change', $currentInHistory?->score_change ?? 0);

        // Radar chart: impact dimension scores, current vs previous
        $dimensionData = [
            'labels' => ['Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => [
                (int) $assessment->impact_financial,
                (int) $assessment->impact_operational,
                (int) $assessment->impact_reputational,
                (int) $assessment->impact_regulatory,
                (int) ($assessment->impact_strategic ?? 0),
            ],
            'previous' => $previousAssessment ? [
                (int) $previousAssessment->impact_financial,
                (int) $previousAssessment->impact_operational,
                (int) $previousAssessment->impact_reputational,
                (int) $previousAssessment->impact_regulatory,
                (int) ($previousAssessment->impact_strategic ?? 0),
            ] : [0, 0, 0, 0, 0],
        ];

        // Bar chart: side-by-side comparison with the previous assessment
        $comparisonData = [
            'labels' => ['Likelihood', 'Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => [
                (int) $assessment->likelihood_score,
                (int) $assessment->impact_financial,
                (int) $assessment->impact_operational,
                (int) $assessment->impact_reputational,
                (int) $assessment->impact_regulatory,
                (int) ($assessment->impact_strategic ?? 0),
            ],
            'previous' => $previousAssessment ? [
                (int) $previousAssessment->likelihood_score,
                (int) $previousAssessment->impact_financial,
                (int) $previousAssessment->impact_operational,
                (int) $previousAssessment->impact_reputational,
                (int) $previousAssessment->impact_regulatory,
                (int) ($previousAssessment->impact_strategic ?? 0),
            ] : [0, 0, 0, 0, 0, 0],
        ];

        return view('risk.assessments.show', compact(
            'assessment', 'previousAssessment',
            'assessmentHistory', 'dimensionData', 'comparisonData',
            'actionPlans', 'kris', 'effectiveness'
        ));
    }

    /**
     * Edit a draft — the same 13-step form, pre-filled.
     */
    public function edit(RiskAssessment $assessment)
    {
        $this->authorizeTenant($assessment);

        if (! in_array($assessment->status, ['draft', 'rejected'])) {
            return redirect()->route('risk.assessments.show', $assessment)
                ->with('error', 'Only draft or rejected assessments can be edited.');
        }

        return view('risk.assessments.create', $this->formData($assessment->risk, $assessment));
    }

    /**
     * Update an assessment (only if draft or in_review).
     */
    public function update(Request $request, RiskAssessment $assessment)
    {
        $this->authorizeTenant($assessment);

        if (! in_array($assessment->status, ['draft', 'in_review', 'rejected'])) {
            return back()->with('error', 'Only draft or in-review assessments can be updated.');
        }

        $risk = $assessment->risk;
        $validated = $this->validateChain($request, $risk);

        return DB::transaction(function () use ($assessment, $validated, $risk, $request) {
            $this->persistChain($assessment, $validated, $risk, $request);

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', 'Assessment updated.');
        });
    }

    private function authorizeTenant(RiskAssessment $assessment): void
    {
        abort_unless(
            $assessment->organization_id === TenantContext::organizationId(),
            403,
            'Unauthorized access to this assessment.'
        );

        // WP-00 node scoping, inherited from the risk being assessed. Folded
        // into the existing tenant guard rather than added at each call site:
        // every method that touches a bound assessment already calls this one,
        // so a method added later is scoped without anyone remembering to.
        // 404 rather than 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
    }

    /**
     * Submit assessment from draft to in_review.
     */
    public function submit(RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        $orgId = TenantContext::organizationId();

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if (! in_array($assessment->status, ['draft', 'rejected'])) {
            return back()->with('error', 'Only draft or rejected assessments can be submitted for review.');
        }

        // WP-06. The workflow engine raises the task, resolves the reviewer and
        // notifies them. Where a tenant has not published the definition yet,
        // the status change below is what it always was.
        if (! $approvals->submit('risk_assessment_approval', $assessment, [], auth()->user())) {
            $approvals->markSubmitted($assessment);

            foreach (User::role(['risk-manager', 'chief-risk-officer'])->where('organization_id', $orgId)->get() as $approver) {
                NotificationService::send(
                    $orgId,
                    $approver->id,
                    'approval_request',
                    'Risk assessment awaiting review: ASS-'.str_pad($assessment->id, 4, '0', STR_PAD_LEFT),
                    "Assessment for risk {$assessment->risk?->risk_code} has been submitted for review.",
                    ['entity_type' => 'risk_assessment', 'entity_id' => $assessment->id]
                );
            }
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment submitted for review.');
    }

    /**
     * Approve assessment and update parent risk scores.
     */
    public function approve(Request $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can approve this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be approved.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        // WP-06. Pushing the approved scores onto the parent risk and firing
        // AssessmentApproved now lives in RiskAssessmentBinding, so it happens
        // however the decision was reached — this screen, My Tasks, the API, or
        // an SLA auto-approval — rather than only when this method runs.
        if (! $approvals->decide($assessment, 'approve', $request->user(), $validated)) {
            DB::transaction(fn () => $approvals->decideDirectly(
                $assessment, 'approve', $request->user(), $validated['comments'] ?? null
            ));
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment approved and risk scores updated.');
    }

    /**
     * Reject assessment with comments.
     */
    public function reject(Request $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can reject this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        // The reason lands on review_comments either way — the binding writes
        // it — and the decision timeline now lives on the workflow instance
        // rather than in approval_requests.
        $decided = $approvals->decide($assessment, 'reject', $request->user(), [
            'comments' => $validated['rejection_reason'],
        ]);

        if (! $decided) {
            $approvals->decideDirectly($assessment, 'reject', $request->user(), $validated['rejection_reason']);
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment rejected. The assessor has been notified.');
    }

    /**
     * Assessor resubmits a rejected assessment — status moves back to draft.
     */
    public function resubmit(RiskAssessment $assessment)
    {
        abort_unless(auth()->user()->can('resubmit-risk-assessment', $assessment), 403,
            'Only the original assessor can resubmit.');

        if ($assessment->status !== 'rejected') {
            return back()->with('error', 'Only rejected assessments can be resubmitted.');
        }

        $assessment->update([
            'status' => 'draft',
            'review_comments' => null,
        ]);

        return back()->with('success', 'Assessment returned to draft. Edit and submit again for review.');
    }
}
