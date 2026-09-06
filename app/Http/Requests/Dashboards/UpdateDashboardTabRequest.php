<?php

namespace App\Http\Requests\Dashboards;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH risk/dashboards/{dashboard}/tabs/{tab} — rename a tab.
 *
 * `label` is required where creating a tab allows none: a new tab may be
 * unnamed, but renaming one to nothing would leave it unreachable.
 */
class UpdateDashboardTabRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:60'],
        ];
    }
}
