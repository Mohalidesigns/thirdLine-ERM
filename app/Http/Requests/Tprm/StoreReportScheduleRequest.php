<?php

namespace App\Http\Requests\Tprm;

use App\Models\Tprm\ReportSchedule;
use App\Services\Tprm\Reporting\Operational\OperationalReportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules for a standing report schedule — FR-RPT-09.
 *
 * THE REPORT'S OWN PERMISSION IS CHECKED HERE, NOT ONLY AT SEND TIME. A user
 * who cannot read the screening log must not be able to schedule it to
 * themselves, which would be a permission bypass with a one-day delay.
 *
 * DAY-OF-MONTH IS CAPPED AT 28. A schedule set for the 31st silently skips
 * four months a year, and nobody notices an email that did not arrive.
 */
class StoreReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.report.export') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_key' => ['required', 'string', Rule::in(app(OperationalReportRegistry::class)->keys())],
            'name' => ['required', 'string', 'max:120'],
            'frequency' => ['required', Rule::in(ReportSchedule::FREQUENCIES)],
            'day_of_week' => [
                Rule::requiredIf(fn () => $this->input('frequency') === ReportSchedule::FREQUENCY_WEEKLY),
                'nullable', 'integer', 'min:1', 'max:7',
            ],
            'day_of_month' => [
                Rule::requiredIf(fn () => $this->input('frequency') === ReportSchedule::FREQUENCY_MONTHLY),
                'nullable', 'integer', 'min:1', 'max:'.ReportSchedule::MAX_DAY_OF_MONTH,
            ],
            'send_at' => ['required', 'date_format:H:i'],
            'format' => ['required', Rule::in(ReportSchedule::FORMATS)],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['required', 'email:rfc'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $key = $this->input('report_key');

            if (! is_string($key) || $validator->errors()->has('report_key')) {
                return;
            }

            $report = app(OperationalReportRegistry::class)->find($key);

            if (! $this->user()->can($report->permission())) {
                // Scheduling it to yourself would be a permission bypass with
                // a one-day delay.
                $validator->errors()->add(
                    'report_key',
                    'You cannot schedule a report you cannot read. This one needs '.$report->permission().'.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_month.max' => 'A monthly schedule can be set up to day '.ReportSchedule::MAX_DAY_OF_MONTH
                .'. A later day would silently skip February, and nobody notices an email that did not arrive.',
            'recipients.*.email' => 'Every recipient must be a valid email address. They are addresses rather '
                .'than users on purpose — a distribution list is frequently a shared mailbox.',
        ];
    }
}
