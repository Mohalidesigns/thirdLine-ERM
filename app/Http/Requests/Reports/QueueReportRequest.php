<?php

namespace App\Http\Requests\Reports;

use App\Jobs\GenerateReportJob;
use Illuminate\Foundation\Http\FormRequest;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * POST risk/reports/queue — generate a report in the background.
 *
 * `format` is restricted to what the renderer ACTUALLY PRODUCES, plus the
 * "excel" alias the existing UI posts. The validation this replaced accepted
 * pdf, excel, html and pptx and wrote a CSV for all four — a report that
 * downloads with the extension you asked for and the contents of something
 * else.
 */
class QueueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.generate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report_type' => ['required', 'in:'.implode(',', GenerateReportJob::TYPES)],
            'name' => ['nullable', 'string', 'max:200'],
            'format' => ['nullable', 'in:'.implode(',', DocumentRenderer::SUPPORTED).',excel'],
            'as_at' => ['nullable', 'date'],
        ];
    }
}
