<?php

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/periods/select — change the reporting period every page is "as at".
 *
 * `redirect` is where the user is sent back to, and it is validated as a
 * bounded string here and resolved against the application's own routes by the
 * controller — a raw redirect target taken from input is an open redirect, and
 * the rule alone cannot prevent that.
 */
class SelectPeriodRequest extends FormRequest
{
    /**
     * Every authenticated role holds dashboard.view, which is what the route
     * requires: selecting a period is not a privileged act, and what the pages
     * then show is decided by tenancy and per-object permissions.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'in:day,week,month,quarter,half,year,custom'],
            'direction' => ['nullable', 'in:previous,next,current'],
            'redirect' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
