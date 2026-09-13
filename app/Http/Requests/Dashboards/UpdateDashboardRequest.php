<?php

namespace App\Http\Requests\Dashboards;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH risk/dashboards/{dashboard} — a dashboard's own settings.
 *
 * `sometimes` throughout because the builder autosaves one field at a time; a
 * `required` rule here would make every partial save fail.
 */
class UpdateDashboardRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'object_type_id' => ['sometimes', 'nullable', 'integer'],
            'role_ids' => ['sometimes', 'nullable', 'array'],
            'role_ids.*' => ['integer'],
            'is_default_for_role' => ['sometimes', 'boolean'],
        ];
    }
}
