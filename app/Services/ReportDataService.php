<?php

namespace App\Services;

use App\Models\Control;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One data source per report, shared by every output format.
 *
 * The reason this exists: the report screens, the CSV export and the (absent)
 * PDF each built their own numbers, so the same report could disagree with
 * itself depending on how you asked for it. A payload here carries both the
 * narrative shape a PDF template needs and the `headers`/`rows` projection a
 * spreadsheet needs, from the same queries.
 */
class ReportDataService
{
    /**
     * @param  array<string,mixed>  $parameters
     * @return array<string,mixed>
     */
    public function payload(string $reportType, Organization $organization, CarbonImmutable $asAt, array $parameters = []): array
    {
        return match ($reportType) {
            'executive' => $this->executive($organization, $asAt),
            'regulatory' => $this->regulatory($organization, $asAt),
            'risk_register' => $this->riskRegister($organization, $asAt, $parameters),
            default => throw new InvalidArgumentException("Unknown report type [{$reportType}]."),
        };
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>
     */
    private function executive(Organization $organization, CarbonImmutable $asAt): array
    {
        $orgId = $organization->id;

        $risks = Risk::where('organization_id', $orgId)->where('status', 'active')->get();
        $controls = Control::where('organization_id', $orgId)->get();

        $ytdLossKobo = (int) LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $asAt->year)
            ->sum('gross_loss_amount_kobo');

        $plans = TreatmentPlan::where('organization_id', $orgId)->get();
        $ratedControls = $controls->whereNotNull('effectiveness_rating');

        $topRisks = $risks
            ->sortByDesc(fn ($r) => $r->residual_score ?? $r->inherent_score ?? 0)
            ->take(15)
            ->values();

        return [
            'view' => 'reports.pdf.executive',
            'title' => 'Executive Risk Report',
            'subtitle' => 'Enterprise risk position summary',
            'periodLabel' => $asAt->format('F Y'),
            'sections' => [
                ['anchor' => 'section-position', 'title' => 'Risk position'],
                ['anchor' => 'section-profile', 'title' => 'Profile by category'],
                ['anchor' => 'section-top-risks', 'title' => 'Top risks'],
                ['anchor' => 'section-indicators', 'title' => 'Indicators and controls'],
            ],

            'summary' => [
                'total_risks' => $risks->count(),
                'critical' => $risks->where('residual_rating', 'Critical')->count(),
                'high' => $risks->where('residual_rating', 'High')->count(),
                'unrated' => $risks->whereNull('residual_rating')->count(),
                'ytd_loss_kobo' => $ytdLossKobo,
                'open_issues' => Issue::where('organization_id', $orgId)
                    ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])->count(),
                'red_kris' => KeyRiskIndicator::where('organization_id', $orgId)
                    ->where('current_status', 'red')->count(),
                'total_kris' => KeyRiskIndicator::where('organization_id', $orgId)->count(),
                'controls_total' => $controls->count(),
                'controls_rated' => $ratedControls->count(),
                // Effectiveness is expressed over the controls that carry a
                // rating, and the denominator is shown alongside. A rate over
                // all controls would silently count untested ones as failures.
                'controls_effective_pct' => $ratedControls->count() > 0
                    ? round($ratedControls->where('effectiveness_rating', 'effective')->count() / $ratedControls->count() * 100, 1)
                    : null,
                'treatments_total' => $plans->count(),
                'treatments_completed' => $plans->where('status', 'completed')->count(),
                'treatments_overdue' => $plans->filter(fn ($p) => ! in_array($p->status, ['completed', 'cancelled'], true)
                    && $p->target_date !== null
                    && CarbonImmutable::parse($p->target_date)->lessThan($asAt))->count(),
            ],

            'byCategory' => $risks
                ->groupBy(fn ($r) => $r->category?->name ?? 'Uncategorised')
                ->map(fn ($group) => [
                    'count' => $group->count(),
                    'critical' => $group->where('residual_rating', 'Critical')->count(),
                    'high' => $group->where('residual_rating', 'High')->count(),
                    'mean_residual' => $group->whereNotNull('residual_score')->isNotEmpty()
                        ? round((float) $group->whereNotNull('residual_score')->avg('residual_score'), 1)
                        : null,
                ])
                ->sortByDesc('count'),

            'topRisks' => $topRisks,

            // Spreadsheet projection of the same content.
            'sheet_name' => 'Executive Summary',
            'headers' => ['Risk Code', 'Title', 'Category', 'Owner', 'Inherent Score', 'Residual Score', 'Residual Rating', 'Status'],
            'rows' => $topRisks->map(fn ($r) => [
                $r->risk_code,
                $r->title,
                $r->category?->name ?? '',
                $r->riskOwner?->name ?? '',
                $r->inherent_score,
                $r->residual_score,
                $r->residual_rating ?? '',
                $r->status,
            ])->all(),
            'meta' => [
                'Organisation' => $organization->name,
                'Report' => 'Executive Risk Report',
                'Position as at' => $asAt->format('d M Y'),
                'Generated' => CarbonImmutable::now()->format('d M Y H:i'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function regulatory(Organization $organization, CarbonImmutable $asAt): array
    {
        $orgId = $organization->id;

        $deadlines = RegulatoryDeadline::where('organization_id', $orgId)
            ->with(['responsible', 'filings'])
            ->orderBy('deadline_date')
            ->get();

        $circulars = RegulatoryCircular::where('organization_id', $orgId)
            ->orderByDesc('date_issued')
            ->get();

        $reportableLosses = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $asAt->year)
            ->orderByDesc('gross_loss_amount_kobo')
            ->get();

        return [
            'view' => 'reports.pdf.regulatory',
            'title' => 'Regulatory Compliance Report',
            'subtitle' => 'Filing obligations, directives and reportable losses',
            'periodLabel' => $asAt->format('F Y'),
            'sections' => [
                ['anchor' => 'section-filings', 'title' => 'Filing obligations'],
                ['anchor' => 'section-directives', 'title' => 'Regulator directives'],
                ['anchor' => 'section-losses', 'title' => 'Reportable loss events'],
            ],

            'deadlines' => $deadlines,
            'overdueDeadlines' => $deadlines->filter(fn ($d) => $d->isOverdue()),
            'circulars' => $circulars,
            'losses' => $reportableLosses,
            'summary' => [
                'deadlines_total' => $deadlines->count(),
                'deadlines_overdue' => $deadlines->filter(fn ($d) => $d->isOverdue())->count(),
                'circulars_total' => $circulars->count(),
                'circulars_non_compliant' => $circulars
                    ->filter(fn ($c) => strtolower((string) $c->compliance_status) !== 'compliant')->count(),
                'losses_ytd' => $reportableLosses->count(),
                'losses_gross_kobo' => (int) $reportableLosses->sum('gross_loss_amount_kobo'),
            ],

            'sheet_name' => 'Regulatory',
            'headers' => ['Regulator', 'Return', 'Frequency', 'Deadline', 'Status', 'Responsible'],
            'rows' => $deadlines->map(fn ($d) => [
                $d->regulator,
                $d->title,
                ucfirst((string) $d->frequency),
                $d->deadline_date?->format('Y-m-d') ?? '',
                $d->isOverdue() ? 'Overdue' : ucfirst((string) $d->status),
                $d->responsible?->name ?? '',
            ])->all(),
            'meta' => [
                'Organisation' => $organization->name,
                'Report' => 'Regulatory Compliance Report',
                'Position as at' => $asAt->format('d M Y'),
                'Generated' => CarbonImmutable::now()->format('d M Y H:i'),
            ],
        ];
    }

    /**
     * The custom/filtered risk register extract.
     *
     * @param  array<string,mixed>  $parameters
     * @return array<string,mixed>
     */
    private function riskRegister(Organization $organization, CarbonImmutable $asAt, array $parameters): array
    {
        $query = Risk::where('organization_id', $organization->id)
            ->with(['category', 'businessUnit', 'riskOwner']);

        if (! empty($parameters['categories'])) {
            $query->whereIn('category_id', (array) $parameters['categories']);
        }
        if (! empty($parameters['business_units'])) {
            $query->whereIn('business_unit_id', (array) $parameters['business_units']);
        }
        if (! empty($parameters['ratings'])) {
            $query->whereIn('inherent_rating', (array) $parameters['ratings']);
        }
        if (! empty($parameters['date_from'])) {
            $query->whereDate('created_at', '>=', $parameters['date_from']);
        }
        if (! empty($parameters['date_to'])) {
            $query->whereDate('created_at', '<=', $parameters['date_to']);
        }

        $risks = $query->orderBy('risk_code')->get();

        return [
            'view' => 'reports.pdf.risk-register',
            'title' => $parameters['report_name'] ?? 'Risk Register Extract',
            'subtitle' => 'Filtered extract from the risk register',
            'periodLabel' => $this->periodLabel($parameters),
            'sections' => [
                ['anchor' => 'section-register', 'title' => 'Risk register'],
            ],
            'risks' => $risks,
            'filters' => $this->describeFilters($parameters),

            'sheet_name' => 'Risk Register',
            'headers' => [
                'Risk Code', 'Title', 'Category', 'Business Unit', 'Owner',
                'Inherent Score', 'Inherent Rating', 'Residual Score', 'Residual Rating',
                'Control Effectiveness (%)', 'Treatment Strategy', 'Status', 'Identified', 'Last Assessment',
            ],
            'rows' => $risks->map(fn ($r) => [
                $r->risk_code,
                $r->title,
                $r->category?->name ?? '',
                $r->businessUnit?->name ?? '',
                $r->riskOwner?->name ?? '',
                $r->inherent_score,
                $r->inherent_rating ?? '',
                $r->residual_score,
                $r->residual_rating ?? '',
                $r->control_effectiveness_pct,
                $r->treatment_strategy ?? '',
                $r->status ?? '',
                $r->date_identified?->format('Y-m-d') ?? '',
                $r->last_assessment_date?->format('Y-m-d') ?? '',
            ])->all(),
            'meta' => [
                'Organisation' => $organization->name,
                'Report' => $parameters['report_name'] ?? 'Risk Register Extract',
                'Period' => $this->periodLabel($parameters),
                'Generated' => CarbonImmutable::now()->format('d M Y H:i'),
                'Rows' => (string) $risks->count(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $parameters
     */
    private function periodLabel(array $parameters): string
    {
        $from = $parameters['date_from'] ?? null;
        $to = $parameters['date_to'] ?? null;

        if (! $from && ! $to) {
            return 'All time';
        }

        return trim(($from ?? 'earliest').' to '.($to ?? 'today'));
    }

    /**
     * A human-readable statement of what was filtered, so a reader can tell
     * whether an extract is the whole register or a slice of it.
     *
     * @param  array<string,mixed>  $parameters
     * @return list<string>
     */
    private function describeFilters(array $parameters): array
    {
        $described = [];

        if (! empty($parameters['categories'])) {
            $described[] = count((array) $parameters['categories']).' category filter(s) applied';
        }
        if (! empty($parameters['business_units'])) {
            $described[] = count((array) $parameters['business_units']).' business unit filter(s) applied';
        }
        if (! empty($parameters['ratings'])) {
            $described[] = 'Inherent rating limited to: '.implode(', ', (array) $parameters['ratings']);
        }
        if (! empty($parameters['date_from']) || ! empty($parameters['date_to'])) {
            $described[] = 'Created between '.$this->periodLabel($parameters);
        }

        return $described === [] ? ['No filters applied — this is the full register.'] : $described;
    }
}
