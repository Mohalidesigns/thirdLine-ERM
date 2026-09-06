<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Validation\Rule;

/**
 * Amend a connector (migration Phase 6.7).
 *
 * `type` is absent: changing a connector's driver would leave it holding a
 * configuration written for a different one, and the screen has never offered
 * it. Everything else is the same, including the save-time URL check.
 */
class UpdateConnectorRequest extends StoreConnectorRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('connector'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['type'], $rules['credentials']);

        return array_merge($rules, [
            // Credentials are only overwritten when something was actually
            // typed: the form renders them blank because they are never sent to
            // the browser, so saving the page must not wipe them.
            'credentials' => ['nullable', 'array'],
            'schedule' => ['nullable', 'string', Rule::in(array_keys((array) config('connectors.schedules')))],
        ]);
    }
}
