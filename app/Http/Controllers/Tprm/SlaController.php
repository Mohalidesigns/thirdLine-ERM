<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Sla;
use App\Models\Tprm\SlaMeasurement;
use App\Services\Tprm\Contracts\SlaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Service levels, their measurements and the credit register — FR-CTR-07.
 *
 * THE MISSING PERIODS ARE ON THE SCREEN BESIDE THE TREND, and that is the
 * point of difference. A chart that draws only the points it has shows a
 * provider who stopped reporting after two bad months as a provider with a
 * perfect record. The gaps are listed as gaps.
 */
class SlaController extends Controller
{
    public function __construct(private readonly SlaService $slas) {}

    public function index(Request $request, Engagement $engagement)
    {
        Gate::authorize('view', $engagement);

        $slas = Sla::query()
            ->where('engagement_id', $engagement->getKey())
            ->with('contract:id,reference,title')
            ->orderBy('metric_name')
            ->get();

        return Inertia::render('Tprm/Slas/Index', [
            'engagement' => [
                'id' => $engagement->getKey(),
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'url' => route('tprm.engagements.show', $engagement),
            ],
            'slas' => $slas->map(fn (Sla $sla) => $this->slaPayload($sla))->values()->all(),
            'credits' => $this->slas->creditRegister($engagement->getKey()),
            'can' => [
                'manage' => $request->user()->can('tprm.contract.manage'),
            ],
        ]);
    }

    /**
     * One service level as the screen reads it.
     *
     * A method rather than an inline closure so the shape has somewhere to be
     * documented — and so the two figures that matter, the consecutive-breach
     * count and the missing periods, sit next to the comment explaining why a
     * gap in reporting is not a compliant month.
     *
     * @return array<string, mixed>
     */
    private function slaPayload(Sla $sla): array
    {
        return [
            'id' => $sla->getKey(),
            'metric_code' => $sla->metric_code,
            'metric_name' => $sla->metric_name,
            'unit' => $sla->unit,
            'target' => $sla->targetLabel(),
            'target_operator' => $sla->target_operator,
            'target_value' => (float) $sla->target_value,
            'window' => $sla->measurement_window,
            'data_source' => $sla->data_source,
            'is_active' => $sla->is_active,
            'contract' => $sla->contract?->reference,
            'penalty_terms' => $sla->penalty_terms,
            'consecutive_breaches' => $this->slas->consecutiveBreaches($sla),
            // A missing month is not a compliant month: a provider whose
            // reporting quietly stops looks identical to a perfect record on
            // any chart that draws only the points it has.
            'missing_periods' => $this->slas->missingPeriods($sla),
            'measurements' => $this->slas->history($sla)->map(fn (SlaMeasurement $m) => [
                'id' => $m->getKey(),
                'period_start' => $m->period_start?->toDateString(),
                'period_end' => $m->period_end?->toDateString(),
                'actual' => (float) $m->actual_value,
                'is_breach' => $m->is_breach,
                'severity' => $m->breach_severity,
                'credit_claimed_minor' => $m->credit_claimed_minor,
                'credit_received_minor' => $m->credit_received_minor,
            ])->values()->all(),
        ];
    }

    public function store(Request $request, Engagement $engagement)
    {
        Gate::authorize('tprm.contract.manage');

        $validated = $request->validate([
            'contract_id' => 'nullable|integer',
            'metric_code' => 'required|string|max:60',
            'metric_name' => 'required|string|max:200',
            'unit' => 'nullable|string|max:40',
            // The operator is required and never inferred: 99.9% availability
            // is a floor and 4 hours resolution is a ceiling, and guessing
            // which from the label is how a detector reports the opposite of
            // the truth.
            'target_operator' => 'required|in:gte,lte,eq',
            'target_value' => 'required|numeric',
            'measurement_window' => 'required|in:'.implode(',', Sla::WINDOWS),
            'data_source' => 'required|in:'.implode(',', Sla::DATA_SOURCES),
            'penalty_terms' => 'nullable|string|max:2000',
            'credit_formula' => 'nullable|string|max:2000',
        ]);

        Sla::create($validated + [
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'The service level was recorded.');
    }

    public function recordMeasurement(Request $request, Sla $sla)
    {
        Gate::authorize('tprm.contract.manage');

        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'actual_value' => 'required|numeric',
            'credit_claimed_minor' => 'nullable|integer|min:0',
            'credit_received_minor' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'evidence_document_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
        ]);

        $measurement = $this->slas->record($sla, $validated, $request->user()->id);

        return back()->with(
            $measurement->is_breach ? 'error' : 'success',
            $measurement->is_breach
                ? sprintf(
                    'Recorded, and it breaches the target (%s). Proposed severity: %s. This is consecutive '
                    .'breach %d.',
                    $sla->targetLabel(),
                    $measurement->breach_severity,
                    $this->slas->consecutiveBreaches($sla),
                )
                : 'Recorded. The target was met for this period.'
        );
    }

    /**
     * Import a vendor's monthly report.
     *
     * Unmatched metric codes are REPORTED rather than dropped: a report whose
     * metric was renamed would otherwise import as a silent partial success,
     * and the months it skipped would look like months nobody breached.
     */
    public function import(Request $request, Engagement $engagement)
    {
        Gate::authorize('tprm.contract.manage');

        $validated = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.metric_code' => 'required|string|max:60',
            'rows.*.period_start' => 'required|date',
            'rows.*.period_end' => 'required|date',
            'rows.*.actual_value' => 'required|numeric',
        ]);

        $result = $this->slas->import($engagement->getKey(), $validated['rows'], $request->user()->id);

        $message = sprintf('%d measurement(s) recorded, %d of them breaching.', $result['recorded'], $result['breaches']);

        if ($result['unmatched'] !== []) {
            return back()->with('error', $message.' These metric codes matched no service level on this '
                .'engagement and were not recorded: '.implode(', ', $result['unmatched']).'.');
        }

        return back()->with('success', $message);
    }
}
