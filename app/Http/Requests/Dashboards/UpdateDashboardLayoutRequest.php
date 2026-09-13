<?php

namespace App\Http\Requests\Dashboards;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT risk/dashboards/{dashboard}/layout — where the widgets sit, after a drag.
 *
 * `present` rather than `required` on `items`: emptying a tab is a legitimate
 * layout, and `required` rejects an empty array, so a user who removed their
 * last widget could not save the result.
 */
class UpdateDashboardLayoutRequest extends FormRequest
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
            'tab' => ['required', 'string'],
            'items' => ['present', 'array'],
            'items.*.position' => ['required', 'integer', 'min:0'],
            'items.*.x' => ['required', 'integer'],
            'items.*.y' => ['required', 'integer'],
            'items.*.w' => ['required', 'integer'],
            'items.*.h' => ['required', 'integer'],
        ];
    }
}
