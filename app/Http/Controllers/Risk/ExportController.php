<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\Control;
use App\Models\GeneratedReport;
use App\Models\Issue;
use App\Models\LossEvent;
use App\Models\RiskAppetite;
use App\Models\RiskControlMapping;
use App\Models\KeyRiskIndicator;
use App\Models\SimulationRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Export risk register as CSV.
     */
    public function risks(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $risks = Risk::where('organization_id', $orgId)
            ->with(['category', 'riskOwner', 'businessUnit'])
            ->orderByDesc('inherent_score')
            ->get();

        $headers = [
            'Risk Code', 'Title', 'Category', 'Risk Type', 'Status',
            'Business Unit', 'Owner',
            'Inherent Likelihood', 'Inherent Impact', 'Inherent Score', 'Inherent Rating',
            'Residual Likelihood', 'Residual Impact', 'Residual Score', 'Residual Rating',
            'Treatment Strategy', 'Financial Exposure (NGN)',
            'Date Identified', 'Next Review Date',
        ];

        $rows = $risks->map(fn($r) => [
            $r->risk_code,
            $r->title,
            $r->category->name ?? '',
            $r->risk_type ?? '',
            $r->status,
            $r->businessUnit->name ?? '',
            $r->riskOwner->name ?? '',
            $r->inherent_likelihood,
            $r->inherent_impact,
            $r->inherent_score,
            $r->inherent_rating,
            $r->residual_likelihood,
            $r->residual_impact,
            $r->residual_score,
            $r->residual_rating,
            $r->treatment_strategy ?? '',
            $r->financial_exposure_ngn ?? 0,
            $r->date_identified,
            $r->next_review_date,
        ]);

        return $this->streamCsv("risk_register_export.csv", $headers, $rows);
    }

    /**
     * Export dashboard summary (top risks + KPIs) as CSV.
     */
    public function dashboard(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with(['category', 'riskOwner'])
            ->orderByDesc('residual_score')
            ->get();

        $headers = [
            'Risk Code', 'Title', 'Category', 'Status', 'Owner',
            'Inherent Score', 'Inherent Rating',
            'Residual Score', 'Residual Rating',
            'Treatment Strategy',
        ];

        $rows = $risks->map(fn($r) => [
            $r->risk_code,
            $r->title,
            $r->category->name ?? '',
            $r->status,
            $r->riskOwner->name ?? '',
            $r->inherent_score,
            $r->inherent_rating,
            $r->residual_score,
            $r->residual_rating,
            $r->treatment_strategy ?? '',
        ]);

        return $this->streamCsv("dashboard_risks_export.csv", $headers, $rows);
    }

    /**
     * Export controls library as CSV.
     */
    public function controls(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $controls = Control::where('organization_id', $orgId)
            ->orderBy('control_code')
            ->get();

        $headers = [
            'Control Code', 'Name', 'Description', 'Type', 'Nature',
            'Frequency', 'Automation Level', 'Effectiveness Rating', 'Effectiveness %',
            'Status', 'Last Test Date', 'Next Test Due',
        ];

        $rows = $controls->map(fn($c) => [
            $c->control_code,
            $c->name,
            $c->description ?? '',
            $c->control_type ?? '',
            $c->control_nature ?? '',
            $c->frequency ?? '',
            $c->automation_level ?? '',
            $c->effectiveness_rating ?? '',
            $c->effectiveness_pct ?? '',
            $c->status ?? '',
            $c->last_test_date ?? '',
            $c->next_test_due ?? '',
        ]);

        return $this->streamCsv("controls_export.csv", $headers, $rows);
    }

    /**
     * Export issues as CSV.
     */
    public function issues(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $issues = Issue::where('organization_id', $orgId)
            ->with(['issueOwner', 'businessUnit'])
            ->orderByDesc('created_at')
            ->get();

        $headers = [
            'Reference', 'Title', 'Source', 'Category', 'Priority', 'Status',
            'Business Unit', 'Owner',
            'Regulatory Reportable', 'Escalation Level',
            'Target Resolution Date', 'Actual Resolution Date',
            'Progress %', 'Created At',
        ];

        $rows = $issues->map(fn($i) => [
            $i->issue_reference,
            $i->title ?? $i->issue_title ?? '',
            $i->issue_source ?? '',
            $i->issue_category ?? '',
            $i->priority ?? '',
            $i->issue_status ?? '',
            $i->businessUnit->name ?? '',
            $i->issueOwner->name ?? '',
            $i->regulatory_reportable ? 'Yes' : 'No',
            $i->escalation_level ?? $i->current_escalation_level ?? '',
            $i->target_resolution_date ?? $i->remediation_due_date ?? '',
            $i->actual_resolution_date ?? $i->actual_close_date ?? '',
            $i->progress_percentage ?? '',
            $i->created_at?->format('Y-m-d'),
        ]);

        return $this->streamCsv("issues_export.csv", $headers, $rows);
    }

    /**
     * Export issues ageing report as CSV.
     */
    public function issuesAgeing(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $issues = Issue::where('organization_id', $orgId)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->with(['issueOwner', 'businessUnit'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($issue) {
                $ageDays = $issue->created_at ? now()->diffInDays($issue->created_at) : 0;
                $issue->age_days = $ageDays;
                $issue->age_bucket = match (true) {
                    $ageDays <= 30 => '0-30 days',
                    $ageDays <= 60 => '31-60 days',
                    $ageDays <= 90 => '61-90 days',
                    default => '90+ days',
                };
                return $issue;
            });

        $headers = [
            'Reference', 'Title', 'Priority', 'Status', 'Owner',
            'Business Unit', 'Age (Days)', 'Age Bucket',
            'Target Resolution Date', 'Created At',
        ];

        $rows = $issues->map(fn($i) => [
            $i->issue_reference,
            $i->title ?? $i->issue_title ?? '',
            $i->priority ?? '',
            $i->issue_status ?? '',
            $i->issueOwner->name ?? '',
            $i->businessUnit->name ?? '',
            $i->age_days,
            $i->age_bucket,
            $i->target_resolution_date ?? $i->remediation_due_date ?? '',
            $i->created_at?->format('Y-m-d'),
        ]);

        return $this->streamCsv("issues_ageing_export.csv", $headers, $rows);
    }

    /**
     * Export loss events as CSV.
     */
    public function lossEvents(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $events = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->get();

        $headers = [
            'Reference', 'Title', 'Date of Loss', 'Date Discovered',
            'Basel L1 Category', 'CBN Risk Category', 'Severity', 'Status',
            'Gross Loss (NGN)', 'Recovery (NGN)', 'Net Loss (NGN)',
            'Regulatory Reportable', 'Created At',
        ];

        $rows = $events->map(fn($e) => [
            $e->event_reference,
            $e->title ?? $e->event_title ?? '',
            $e->date_of_loss,
            $e->date_discovered ?? '',
            $e->basel_l1_category ?? $e->basel_event_type ?? '',
            $e->cbn_risk_category ?? $e->cbn_loss_category ?? '',
            $e->event_severity ?? $e->severity ?? '',
            $e->current_status ?? $e->status ?? '',
            $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0),
            $e->recovery_amount ?? (($e->insurance_recovery_kobo ?? 0) + ($e->other_recovery_kobo ?? 0)) / 100,
            $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) - ($e->insurance_recovery_kobo ?? 0) - ($e->other_recovery_kobo ?? 0)) / 100,
            ($e->is_regulatory_reportable ?? $e->cbn_reportable) ? 'Yes' : 'No',
            $e->created_at?->format('Y-m-d'),
        ]);

        return $this->streamCsv("loss_events_export.csv", $headers, $rows);
    }

    /**
     * Export risk appetite framework as CSV.
     */
    public function appetite(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $appetites = RiskAppetite::where('organization_id', $orgId)
            ->with('category')
            ->orderBy('risk_category_id')
            ->get();

        $headers = [
            'Risk Category', 'Appetite Level', 'Appetite Statement',
            'Tolerance Metric', 'Max Tolerance', 'Target Min', 'Target Max',
            'Current Position', 'Unit of Measure',
            'Effective Date', 'Expiry Date',
        ];

        $rows = $appetites->map(fn($a) => [
            $a->category->name ?? '',
            $a->appetite_level ?? '',
            $a->appetite_statement ?? '',
            $a->tolerance_metric ?? '',
            $a->max_tolerance ?? '',
            $a->target_min ?? '',
            $a->target_max ?? '',
            $a->current_position ?? '',
            $a->unit_of_measure ?? '',
            $a->effective_date ?? '',
            $a->expiry_date ?? '',
        ]);

        return $this->streamCsv("risk_appetite_export.csv", $headers, $rows);
    }

    /**
     * Export RCSA risk-control matrix as CSV.
     */
    public function rcsaMatrix(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $mappings = RiskControlMapping::where('organization_id', $orgId)
            ->with(['risk', 'control'])
            ->get();

        $headers = [
            'Risk Code', 'Risk Title', 'Control Code', 'Control Name',
            'Control Weight', 'Is Key Control', 'Mapping Rationale',
        ];

        $rows = $mappings->map(fn($m) => [
            $m->risk->risk_code ?? '',
            $m->risk->title ?? '',
            $m->control->control_code ?? '',
            $m->control->name ?? '',
            $m->control_weight ?? '',
            $m->is_key_control ? 'Yes' : 'No',
            $m->mapping_rationale ?? '',
        ]);

        return $this->streamCsv("rcsa_matrix_export.csv", $headers, $rows);
    }

    /**
     * Export quantification results as CSV.
     */
    public function quantificationResults(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $runs = SimulationRun::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get();

        $headers = [
            'Reference', 'Status', 'Iterations', 'Horizon (Years)',
            'Correlation Method', 'Started At', 'Completed At',
            'Runtime (Seconds)',
        ];

        $rows = $runs->map(fn($r) => [
            $r->simulation_reference ?? '',
            $r->status ?? '',
            $r->iterations ?? '',
            $r->horizon_years ?? '',
            $r->correlation_method ?? '',
            $r->started_at ?? '',
            $r->completed_at ?? '',
            $r->runtime_seconds ?? '',
        ]);

        return $this->streamCsv("quantification_results_export.csv", $headers, $rows);
    }

    /**
     * Export risk assessments as CSV (same as register with assessment focus).
     */
    public function assessments(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $risks = Risk::where('organization_id', $orgId)
            ->with(['category', 'riskOwner', 'businessUnit'])
            ->orderByDesc('inherent_score')
            ->get();

        $headers = [
            'Risk Code', 'Title', 'Category', 'Business Unit', 'Owner',
            'Inherent Likelihood', 'Inherent Impact', 'Inherent Score', 'Inherent Rating',
            'Control Effectiveness %',
            'Residual Likelihood', 'Residual Impact', 'Residual Score', 'Residual Rating',
            'Target Rating', 'Last Assessment Date', 'Next Review Date',
        ];

        $rows = $risks->map(fn($r) => [
            $r->risk_code,
            $r->title,
            $r->category->name ?? '',
            $r->businessUnit->name ?? '',
            $r->riskOwner->name ?? '',
            $r->inherent_likelihood,
            $r->inherent_impact,
            $r->inherent_score,
            $r->inherent_rating,
            $r->control_effectiveness_pct ?? '',
            $r->residual_likelihood,
            $r->residual_impact,
            $r->residual_score,
            $r->residual_rating,
            $r->target_rating ?? '',
            $r->last_assessment_date ?? '',
            $r->next_review_date ?? '',
        ]);

        return $this->streamCsv("risk_assessments_export.csv", $headers, $rows);
    }

    /**
     * CBN ORMS quarterly loss report CSV.
     */
    public function lossEventsCbnOrms(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $quarter = $request->input('quarter', 'Q1');
        $year = $request->input('year', date('Y'));

        $quarterMonths = ['Q1' => [1,2,3], 'Q2' => [4,5,6], 'Q3' => [7,8,9], 'Q4' => [10,11,12]];
        $months = $quarterMonths[$quarter] ?? [1,2,3];

        $events = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $year)
            ->whereIn(\DB::raw('MONTH(date_of_loss)'), $months)
            ->orderBy('date_of_loss')
            ->get();

        $headers = [
            'Reference', 'Title', 'Date of Loss', 'Basel L1 Category', 'Basel L2 Category',
            'CBN Risk Category', 'CBN ORMS Event Type', 'Gross Loss (NGN)', 'Recovery (NGN)',
            'Net Loss (NGN)', 'Severity', 'Regulatory Reportable',
        ];

        $rows = $events->map(fn($e) => [
            $e->event_reference,
            $e->title ?? $e->event_title ?? '',
            $e->date_of_loss,
            $e->basel_l1_category ?? '',
            $e->basel_l2_category ?? '',
            $e->cbn_risk_category ?? '',
            $e->cbn_orms_event_type ?? '',
            $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0),
            $e->recovery_amount ?? (($e->insurance_recovery_kobo ?? 0) + ($e->other_recovery_kobo ?? 0)) / 100,
            $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) - ($e->insurance_recovery_kobo ?? 0) - ($e->other_recovery_kobo ?? 0)) / 100,
            $e->event_severity ?? $e->severity ?? '',
            ($e->cbn_reportable || $e->is_regulatory_reportable) ? 'Yes' : 'No',
        ]);

        $file = "cbn_orms_report_{$quarter}_{$year}.csv";
        $this->logGeneration(
            name: "CBN ORMS Report — {$quarter} {$year}",
            reportType: 'cbn_orms',
            fileName: $file,
            downloadRoute: 'risk.export.loss-events.cbn-orms',
            period: "{$quarter} {$year}",
            parameters: compact('quarter', 'year'),
        );
        return $this->streamCsv($file, $headers, $rows);
    }

    /**
     * Basel loss data report CSV.
     */
    public function lossEventsBasel(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $from = $request->input('from_date', now()->startOfYear()->toDateString());
        $to = $request->input('to_date', now()->toDateString());

        $events = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$from, $to])
            ->orderBy('date_of_loss')
            ->get();

        $headers = [
            'Reference', 'Title', 'Date of Loss', 'Date Discovered',
            'Basel L1 Category', 'Basel L2 Category', 'Basel L3 Detail',
            'CBN Product Line', 'Gross Loss (NGN)', 'Insurance Recovery (NGN)',
            'Other Recovery (NGN)', 'Net Loss (NGN)', 'Severity',
        ];

        $rows = $events->map(fn($e) => [
            $e->event_reference,
            $e->title ?? $e->event_title ?? '',
            $e->date_of_loss,
            $e->date_discovered ?? '',
            $e->basel_l1_category ?? '',
            $e->basel_l2_category ?? '',
            $e->basel_l3_detail ?? '',
            $e->cbn_product_line ?? '',
            $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0),
            $e->insurance_recovery ?? ($e->insurance_recovery_kobo ? $e->insurance_recovery_kobo / 100 : 0),
            ($e->other_recovery_kobo ?? 0) / 100,
            $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) - ($e->insurance_recovery_kobo ?? 0) - ($e->other_recovery_kobo ?? 0)) / 100,
            $e->event_severity ?? $e->severity ?? '',
        ]);

        $file = "basel_loss_data_{$from}_{$to}.csv";
        $this->logGeneration(
            name: "Basel Loss Data — {$from} to {$to}",
            reportType: 'basel',
            fileName: $file,
            downloadRoute: 'risk.export.loss-events.basel',
            period: "{$from} to {$to}",
            parameters: compact('from', 'to'),
        );
        return $this->streamCsv($file, $headers, $rows);
    }

    /**
     * Management summary loss report CSV.
     */
    public function lossEventsManagement(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $period = $request->input('period', 'quarterly');

        $startDate = match ($period) {
            'monthly' => now()->startOfMonth(),
            'quarterly' => now()->startOfQuarter(),
            'annual' => now()->startOfYear(),
            'ytd' => now()->startOfYear(),
            default => now()->startOfQuarter(),
        };

        $events = LossEvent::where('organization_id', $orgId)
            ->where('date_of_loss', '>=', $startDate)
            ->orderBy('date_of_loss')
            ->get();

        $headers = [
            'Reference', 'Title', 'Date of Loss', 'Category',
            'Gross Loss (NGN)', 'Net Loss (NGN)', 'Severity', 'Status',
            'Root Cause Summary', 'Corrective Action',
        ];

        $rows = $events->map(fn($e) => [
            $e->event_reference,
            $e->title ?? $e->event_title ?? '',
            $e->date_of_loss,
            $e->cbn_risk_category ?? $e->basel_l1_category ?? '',
            $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0),
            $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) - ($e->insurance_recovery_kobo ?? 0) - ($e->other_recovery_kobo ?? 0)) / 100,
            $e->event_severity ?? $e->severity ?? '',
            $e->current_status ?? $e->status ?? '',
            $e->root_cause_summary ?? $e->initial_root_cause ?? '',
            $e->corrective_action_summary ?? '',
        ]);

        $file = "management_loss_summary_{$period}.csv";
        $this->logGeneration(
            name: "Management Loss Summary — " . ucfirst($period),
            reportType: 'loss_event_management',
            fileName: $file,
            downloadRoute: 'risk.export.loss-events.management',
            period: ucfirst($period),
            parameters: compact('period'),
        );
        return $this->streamCsv($file, $headers, $rows);
    }

    /**
     * NFIU STR report CSV.
     */
    public function lossEventsNfiu(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $from = $request->input('from_date', now()->startOfYear()->toDateString());
        $to = $request->input('to_date', now()->toDateString());

        $events = LossEvent::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('nfiu_reportable', true)
                  ->orWhere('is_regulatory_reportable', true);
            })
            ->when($from, fn($q) => $q->where('date_of_loss', '>=', $from))
            ->when($to, fn($q) => $q->where('date_of_loss', '<=', $to))
            ->orderBy('date_of_loss')
            ->get();

        $headers = [
            'Reference', 'Title', 'Date of Loss', 'Date Discovered',
            'Category', 'Gross Loss (NGN)', 'NFIU Report Type',
            'NFIU STR Reference', 'NFIU Report Filed', 'Severity',
            'Customers Affected', 'Law Enforcement Notified',
        ];

        $rows = $events->map(fn($e) => [
            $e->event_reference,
            $e->title ?? $e->event_title ?? '',
            $e->date_of_loss,
            $e->date_discovered ?? '',
            $e->cbn_risk_category ?? '',
            $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0),
            $e->nfiu_report_type ?? '',
            $e->nfiu_str_reference ?? '',
            $e->nfiu_report_filed ? 'Yes' : 'No',
            $e->event_severity ?? $e->severity ?? '',
            $e->customers_affected_count ?? 0,
            $e->law_enforcement_notified ? 'Yes' : 'No',
        ]);

        $file = "nfiu_str_report_{$from}_{$to}.csv";
        $this->logGeneration(
            name: "NFIU STR Report — {$from} to {$to}",
            reportType: 'nfiu_str',
            fileName: $file,
            downloadRoute: 'risk.export.loss-events.nfiu',
            period: "{$from} to {$to}",
            parameters: compact('from', 'to'),
        );
        return $this->streamCsv($file, $headers, $rows);
    }

    /**
     * Loss events trend analysis CSV.
     */
    public function lossEventsTrends(Request $request): StreamedResponse
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $range = $request->input('range', '12m');

        $months = (int) filter_var($range, FILTER_SANITIZE_NUMBER_INT);
        $startDate = now()->subMonths($months);

        $events = LossEvent::where('organization_id', $orgId)
            ->where('date_of_loss', '>=', $startDate)
            ->orderBy('date_of_loss')
            ->get();

        // Group by month
        $monthly = $events->groupBy(fn($e) => \Carbon\Carbon::parse($e->date_of_loss)->format('Y-m'));

        $headers = ['Month', 'Event Count', 'Total Gross Loss (NGN)', 'Total Net Loss (NGN)', 'Avg Loss (NGN)'];
        $rows = collect();

        foreach ($monthly as $month => $monthEvents) {
            $gross = $monthEvents->sum(fn($e) => $e->gross_loss_amount ?? ($e->gross_loss_amount_kobo ? $e->gross_loss_amount_kobo / 100 : 0));
            $net = $monthEvents->sum(fn($e) => $e->net_loss_amount ?? (($e->gross_loss_amount_kobo ?? 0) - ($e->insurance_recovery_kobo ?? 0) - ($e->other_recovery_kobo ?? 0)) / 100);
            $rows->push([
                $month,
                $monthEvents->count(),
                round($gross, 2),
                round($net, 2),
                $monthEvents->count() > 0 ? round($gross / $monthEvents->count(), 2) : 0,
            ]);
        }

        $file = "loss_events_trend_{$range}.csv";
        $this->logGeneration(
            name: "Loss Event Trends — last " . ($months ?: 12) . ' months',
            reportType: 'loss_event_trends',
            fileName: $file,
            downloadRoute: 'risk.export.loss-events.trends',
            period: "Last {$months} months",
            parameters: compact('range'),
        );
        return $this->streamCsv($file, $headers, $rows);
    }

    /**
     * Full loss event register export CSV.
     */
    public function lossEventsFullExport(Request $request): StreamedResponse
    {
        $this->logGeneration(
            name: "Full Loss Event Register — " . now()->format('d M Y'),
            reportType: 'loss_events_full',
            fileName: 'loss_events_register.csv',
            downloadRoute: 'risk.export.loss-events.full',
            period: 'All time',
            parameters: [],
        );
        return $this->lossEvents($request);
    }

    /**
     * Persist a row in `generated_reports` so the Reports page can list
     * recently produced outputs. The download_route lets the user re-run.
     */
    private function logGeneration(
        string $name,
        string $reportType,
        string $fileName,
        string $downloadRoute,
        ?string $period = null,
        array $parameters = [],
        string $scope = 'loss_events'
    ): void {
        GeneratedReport::create([
            'organization_id' => auth()->user()->organization_id ?? 1,
            'generated_by' => auth()->id(),
            'name' => $name,
            'report_type' => $reportType,
            'scope' => $scope,
            'period' => $period,
            'file_name' => $fileName,
            'download_route' => $downloadRoute,
            'parameters' => $parameters,
        ]);
    }

    /**
     * Stream a CSV response.
     */
    private function streamCsv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, is_array($row) ? $row : $row->toArray());
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
