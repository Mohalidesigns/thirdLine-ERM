<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\SubmitRcsaWorksheetRequest;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\Control;
use App\Models\RiskCategory;
use App\Services\Rcsa\RcsaService;
use App\Services\Rcsa\RcsaWorksheetService;
use App\Support\Rcsa\RcsaCutover;
use App\Support\Rcsa\RcsaProgramme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Risk and Control Self-Assessment (migration Phase 3.8).
 *
 * Every figure comes from RcsaService, the worksheet write from
 * RcsaWorksheetService, and authorisation from RcsaPolicy — which is
 * registered against App\Support\Rcsa\RcsaProgramme in AppServiceProvider
 * because RCSA has no model of its own to discover a policy from.
 */
class RcsaController extends Controller
{
    public function __construct(
        private readonly RcsaService $rcsa,
        private readonly RcsaWorksheetService $worksheets,
    ) {}

    public function dashboard()
    {
        Gate::authorize('viewAny', RcsaProgramme::class);

        return Inertia::render('Rcsa/Dashboard', [
            'kpis' => $this->rcsa->dashboardKpis(),
            'unitProgress' => $this->rcsa->unitProgress(),
            'topRisks' => $this->rcsa->topRisks(),
            'riskDistribution' => $this->rcsa->riskDistribution(),
            'controlEffectiveness' => $this->rcsa->controlEffectiveness(),
        ]);
    }

    public function worksheet(Request $request)
    {
        Gate::authorize('viewAny', RcsaProgramme::class);

        $orgId = TenantContext::organizationId();

        return Inertia::render('Rcsa/Worksheet', [
            'canSubmit' => Gate::allows('submit', RcsaProgramme::class),
            'businessUnits' => $this->options(BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get()),
            'processes' => $this->options(\App\Models\BusinessProcess::where('organization_id', $orgId)->orderBy('name')->get()),
            // The tenant's OWN taxonomy. The Blade form offered seven hardcoded
            // English category names while this very list was loaded and
            // ignored — see the module notes.
            'categories' => $this->options(RiskCategory::where('organization_id', $orgId)->orderBy('name')->get()),
            'campaigns' => $this->worksheets->selectableCampaigns(),
            // The register risks a line can be linked to. The Blade controller
            // ran this query, paginated and filtered, and the view never read
            // it — so `risks.*.risk_id`, which the validator accepts and the
            // service stores, could never be anything but null.
            'assessableRisks' => $this->rcsa->assessableRisks(
                $request->integer('business_unit_id') ?: null,
                $request->integer('category_id') ?: null,
            ),
            'filters' => [
                'business_unit_id' => $request->integer('business_unit_id') ?: null,
                'category_id' => $request->integer('category_id') ?: null,
            ],
            'mySubmissions' => $this->worksheets->recentSubmissions($request->user())
                ->map(fn (CampaignAssignment $assignment) => [
                    'id' => $assignment->id,
                    'unit' => $assignment->businessUnit?->name,
                    'campaignCode' => $assignment->campaign?->campaign_code,
                    'lines' => (int) $assignment->getAttribute('responses_count'),
                    'status' => $assignment->status,
                    'submittedAt' => $assignment->submitted_at?->format('d M Y, H:i'),
                    'url' => Gate::allows('campaign.view')
                        ? route('risk.campaigns.submission', $assignment)
                        : null,
                ])->all(),
        ]);
    }

    public function storeWorksheet(SubmitRcsaWorksheetRequest $request)
    {
        // §13 step 6, and the only place it can be enforced. "Legacy tables
        // become read-only" cannot be applied literally — this module has no
        // tables of its own and the ones it reads are the enterprise register
        // that half the product depends on. THIS is the legacy write path, so
        // closing it is what read-only means here.
        //
        // A REFUSAL, NOT A REDIRECT. A redirect would drop whatever the
        // respondent had typed; a message on the screen they are already on
        // tells them where the work goes now and lets them copy it across.
        if (app(RcsaCutover::class)->hasCutOver($request->user()->organization_id)) {
            return back()->with('error',
                'This organisation has moved to the new RCSA module, so the old worksheet no longer accepts '
                .'submissions. Your work has not been saved here — file it under RCSA → My Assessments.');
        }

        $result = $this->worksheets->file($request->validated(), $request->user());

        $lines = $result['lines'];
        $code = $result['campaign']->campaign_code;

        $message = $result['submitted']
            ? "Worksheet submitted for review: {$lines} risk line(s) recorded against campaign {$code}."
            : "Draft saved: {$lines} risk line(s) recorded against campaign {$code}.";

        // Land on the submission itself rather than back on an empty worksheet.
        // A worksheet becomes a campaign assignment, not a register risk, so a
        // respondent returned to the blank form had no way of telling where
        // their work had gone — or whether it had been kept at all. Every role
        // holding rcsa.submit also holds campaign.view today; the fallback
        // keeps the flash message meaningful if that ever stops being true
        // rather than bouncing the respondent into a 403.
        if ($request->user()->can('campaign.view')) {
            return redirect()
                ->route('risk.campaigns.submission', $result['assignment'])
                ->with('success', $message);
        }

        return redirect()->route('risk.rcsa.worksheet')->with('success', $message);
    }

    public function controls(Request $request)
    {
        Gate::authorize('viewAny', RcsaProgramme::class);

        $orgId = TenantContext::organizationId();

        // WP-00: a paginated list of individual controls, so it is scoped.
        $query = Control::where('organization_id', $orgId)
            ->visibleTo()
            ->with(['controlOwner', 'businessUnit'])
            ->withCount('riskMappings');

        foreach ([
            'effectiveness' => 'effectiveness_rating',
            'control_type' => 'control_type',
            'business_unit_id' => 'business_unit_id',
        ] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, $request->input($input));
            }
        }

        $controls = $query->orderBy('control_code')->paginate(25)->withQueryString();

        return Inertia::render('Rcsa/Controls', [
            'summary' => $this->rcsa->controlsSummary(),
            'controls' => [
                'data' => collect($controls->items())->map(fn (Control $control) => [
                    'id' => $control->id,
                    'code' => $control->control_code,
                    'name' => $control->name,
                    'type' => $control->control_type,
                    'businessUnit' => $control->businessUnit?->name,
                    'owner' => $control->controlOwner?->name,
                    'effectiveness' => $control->effectiveness_rating,
                    'effectivenessPct' => $control->effectiveness_pct,
                    'linkedRisks' => (int) $control->risk_mappings_count,
                    'lastTested' => $control->last_test_date?->format('d M Y'),
                    'nextDue' => $control->next_test_due?->format('d M Y'),
                    'url' => route('risk.controls.show', $control),
                ])->all(),
                'links' => $controls->linkCollection()->toArray(),
                'meta' => [
                    'from' => $controls->firstItem(),
                    'to' => $controls->lastItem(),
                    'total' => $controls->total(),
                ],
            ],
            'businessUnits' => $this->options(BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get()),
            'filters' => [
                'effectiveness' => $request->input('effectiveness'),
                'control_type' => $request->input('control_type'),
                'business_unit_id' => $request->integer('business_unit_id') ?: null,
            ],
        ]);
    }

    public function matrix(Request $request)
    {
        Gate::authorize('viewAny', RcsaProgramme::class);

        $businessUnitId = $request->integer('business_unit_id') ?: null;

        return Inertia::render('Rcsa/Matrix', array_merge(
            $this->rcsa->matrix($businessUnitId),
            [
                'businessUnits' => $this->options(
                    BusinessUnit::where('organization_id', TenantContext::organizationId())->orderBy('name')->get()
                ),
                'filters' => ['business_unit_id' => $businessUnitId],
                'exportUrl' => route('risk.export.rcsa-matrix'),
            ],
        ));
    }

    /**
     * A lookup list as `{id, name}` options.
     *
     * Templated rather than typed against Model: Eloquent generics are
     * invariant, so a Collection<int, BusinessUnit> is not a
     * Collection<int, Model> and every call site would be an error.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, TModel>  $rows
     * @return list<array{id: int, name: string}>
     */
    private function options(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'id' => (int) $row->getKey(),
            'name' => (string) $row->getAttribute('name'),
        ])->values()->all();
    }
}
