<?php

namespace App\Http\Requests\Reports;

use App\Models\GeneratedReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Build a report to a chosen shape (migration Phase 5.4).
 *
 * The rules are carried across from ReportController::generateCustom()
 * unchanged, with two additions.
 *
 * FIRST, THE FOREIGN KEYS ARE TENANT-BOUND. `categories.*` and
 * `business_units.*` were `integer` and nothing more, so another institution's
 * ids were accepted into the filter. The report query scopes to the
 * organisation anyway, so the practical effect was an empty section rather
 * than a leak — but it is the same class of hole 5.3 found four times, and the
 * rule that closes it costs one line each.
 *
 * SECOND, `sections` is checked against the list the form offers. It was
 * `nullable|array` with no rule on its elements, so any string reached the
 * assembler. That is 4.6's lesson: what a form OFFERS must be a subset of what
 * the validator ACCEPTS, and both should read one list.
 */
class GenerateCustomReportRequest extends FormRequest
{
    /**
     * The sections a custom report can carry, and their labels.
     *
     * Lifted out of the Blade template, which held it as a literal, so the
     * form and the validator read the same list.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'risk_summary' => 'Risk Summary & KPIs',
        'heatmap' => 'Risk Heatmap',
        'top_risks' => 'Top Risks Table',
        'kri_status' => 'KRI Status',
        'treatment_progress' => 'Treatment Progress',
        'loss_events' => 'Loss Events Summary',
        'trend_charts' => 'Trend Charts',
        'appetite_status' => 'Appetite Status',
        'regulatory_compliance' => 'Regulatory Compliance',
        'capital_adequacy' => 'Capital Adequacy',
    ];

    /** The sections a new report starts with. @var list<string> */
    public const DEFAULT_SECTIONS = ['risk_summary', 'top_risks', 'heatmap', 'kri_status'];

    /** @var array<string, string> */
    public const REPORT_TYPES = [
        'summary' => 'Summary Report',
        'detailed' => 'Detailed Report',
        'trend' => 'Trend Analysis',
        'comparison' => 'Period Comparison',
    ];

    /** @var list<string> */
    public const RATINGS = ['Critical', 'High', 'Medium', 'Low'];

    /** @var list<string> */
    public const FORMATS = ['pdf', 'xlsx', 'csv'];

    public function authorize(): bool
    {
        return $this->user()->can('create', GeneratedReport::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'report_name' => ['required', 'string', 'max:200'],
            'report_type' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            // 'excel' is accepted as an alias for xlsx because the existing
            // form posts it; html and pptx are gone rather than silently
            // downgraded.
            'format' => ['nullable', Rule::in(['pdf', 'xlsx', 'excel', 'csv'])],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', Rule::exists('risk_categories', 'id')->where('organization_id', $orgId)],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['string'],
            'business_units' => ['nullable', 'array'],
            'business_units.*' => ['integer', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'sections' => ['nullable', 'array'],
            'sections.*' => [Rule::in(array_keys(self::SECTIONS))],
        ];
    }
}
