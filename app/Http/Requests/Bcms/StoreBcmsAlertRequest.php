<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\AlertTemplate;
use App\Rules\Bcms\NoTemplatePlaceholder;
use App\Rules\Bcms\ValidAudienceRule;
use App\Support\Bcms\AlertTemplateVariables;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Composing an alert — from the EMNS console, or from the crisis room with an
 * incident attached (Gate 2 review #1 defect 13).
 *
 * `incident_id` IS VALIDATED AS A SHAPE, NOT A TENANT-BOUND `Rule::exists()` —
 * same reasoning as `ActivateBcmsPlanRequest`: an incident is visible only to
 * its business unit, not merely its tenant, so `AlertController::store()`
 * resolves the uuid through `Incident::visibleTo($request->user())` and 404s
 * if it does not resolve, rather than trusting a bare tenant-scoped `exists`.
 *
 * `template_id` AND `occurrence_id` ARE TENANT-BOUND `Rule::exists()` (ADR
 * 0024 §3.1) — bare integers before this, which DEVELOPMENT_STANDARD §4
 * forbids across a tenant boundary, and the new `template_variables` rules
 * below need to load the template row anyway.
 *
 * `template_variables` RULES ARE BUILT FROM THE CHOSEN TEMPLATE'S OWN
 * `variables` LIST (ADR 0024 §3.1), via `AlertTemplateVariables`: each
 * declared `OPERATOR` name is required, each `OPERATOR_OPTIONAL` name is
 * `present|nullable` (an explicit empty string is a valid, deliberate
 * "nothing to add" — never the same as not answering). Any key in the
 * submitted map that is not one of the template's own declared
 * operator-entered variables is refused in `withValidator()`'s `after()`
 * hook, because the whitelist is per-template and `rules()` alone cannot
 * express "no key outside this dynamic list". A template declaring a
 * `PER_RECIPIENT` variable is refused outright, with ADR 0024's own message
 * — an operator must not discover that only at release, during an incident.
 */
class StoreBcmsAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.alert.compose') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        $rules = [
            'title' => ['required', 'string', 'max:200'],
            // `bcms_alerts.message` is `NOT NULL` with no default. `nullable`
            // here let a request with no `message` key reach `Alert::create()`
            // as an unset attribute — the column's own `NOT NULL` then 500'd
            // at insert instead of a 422 naming the field. `AlertService::
            // compose()` still defaults a MISSING key to the template's name
            // for direct (non-HTTP) callers; this request requires the
            // operator to type something rather than silently substituting
            // a label for them.
            'message' => ['required', 'string', 'max:4000'],
            'template_id' => [
                'nullable', 'integer',
                // System-catalogue templates carry `organization_id = null`
                // (`tenantIncludesGlobal`) and must remain choosable — the
                // bound is "this tenant's own, OR a shipped system row",
                // never a bare tenant match that would 404 every shipped
                // template.
                // `->withoutTrashed()` — `bcms_alert_templates` soft-deletes.
                // Without it a soft-deleted row still passed `exists()`, so
                // `compose()` created a draft `template_id` could never
                // actually render from (`AlertTemplate::query()` respects
                // the trashed scope everywhere else) — a dead draft, not a
                // named 422.
                Rule::exists('bcms_alert_templates', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->orWhereNull('organization_id')
                )->withoutTrashed(),
            ],
            'severity' => ['required', Rule::in(array_column(AlertSeverity::cases(), 'value'))],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(array_column(ChannelKey::cases(), 'value'))],
            'audience_rule' => ['nullable', 'array', new ValidAudienceRule],
            'occurrence_id' => [
                'nullable', 'integer',
                // `->withoutTrashed()` — same reasoning as `template_id`
                // above; `bcms_exercise_occurrences` soft-deletes too.
                Rule::exists('bcms_exercise_occurrences', 'id')
                    ->where('organization_id', $organizationId)
                    ->withoutTrashed(),
            ],
            'incident_id' => ['nullable', 'string', 'uuid'],
            'response_required' => ['nullable', 'boolean'],
            'ack_window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'template_variables' => ['nullable', 'array'],
        ];

        $template = $this->chosenTemplate();

        if ($template !== null) {
            foreach ($this->declaredVariables($template) as $name) {
                $spec = AlertTemplateVariables::operatorSpec($name);

                if ($spec === null) {
                    continue; // derived or per-recipient — not a form field
                }

                $rules['template_variables.'.$name] = AlertTemplateVariables::isOperator($name)
                    ? ['required', 'string', 'max:'.$spec['max'], new NoTemplatePlaceholder]
                    : ['present', 'nullable', 'string', 'max:'.$spec['max'], new NoTemplatePlaceholder];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // ITEM C — the crisis-room composers' default of "high" (not a
            // real `AlertSeverity`) used to 422 with Laravel's generic "the
            // selected severity is invalid", which names neither the value
            // that was sent nor what would have worked.
            'severity.in' => 'Severity must be one of: '
                .implode(', ', array_column(AlertSeverity::cases(), 'value')).'.',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $template = $this->chosenTemplate();

            if ($template === null) {
                return;
            }

            $declared = $this->declaredVariables($template);

            // ADR 0024 §3.3 — refused at compose, not only at release, and
            // named in the picker with the identical message.
            if (AlertTemplateVariables::needsPerRecipientVariable($declared)) {
                $validator->errors()->add('template_id', AlertTemplateVariables::PER_RECIPIENT_MESSAGE);

                return;
            }

            // ANY KEY NOT ONE OF THIS TEMPLATE'S OWN OPERATOR-ENTERED
            // VARIABLES IS REFUSED — an operator cannot override a derived
            // value (`site_name`, a link) by naming it here, and cannot
            // supply a variable a DIFFERENT template happens to use.
            $supplied = (array) $this->input('template_variables', []);

            // A NUMERIC KEY IS REFUSED, NOT SKIPPED. `template_variables` is
            // sent as a JSON object (`{name: value}`), but a bare list
            // (`["x"]`) decodes to integer keys — silently ignoring them
            // let a caller submit garbage that vanished rather than being
            // told about it, the same failure class as an unclassified
            // variable being a silent no-op instead of a named refusal.
            foreach (array_keys($supplied) as $key) {
                if (! is_string($key)
                    || ! in_array($key, $declared, true)
                    || ! AlertTemplateVariables::isOperatorEntered($key)
                ) {
                    $validator->errors()->add(
                        'template_variables.'.$key,
                        is_string($key)
                            ? sprintf('"%s" is not a variable this template accepts.', $key)
                            : sprintf('"%s" is not a valid template variable name.', $key),
                    );
                }
            }
        });
    }

    private function chosenTemplate(): ?AlertTemplate
    {
        $id = $this->input('template_id');

        return filled($id) ? AlertTemplate::query()->find($id) : null;
    }

    /** @return list<string> */
    private function declaredVariables(AlertTemplate $template): array
    {
        return array_values(array_filter((array) $template->variables, 'is_string'));
    }
}
