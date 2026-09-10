<?php

namespace App\Http\Requests\Dashboards;

use Illuminate\Foundation\Http\FormRequest;

/** POST risk/dashboards/{dashboard}/tabs — add a tab. */
class StoreDashboardTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        // dashboard.manage, which is what the route group requires — there is
        // no DashboardPolicy, and asking for an ability no policy defines
        // denies everyone. Tenancy is settled by the route binding: Dashboard
        // carries the organisation global scope, so another tenant's id is a
        // 404 before this runs.
        return $this->user()?->can('dashboard.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
