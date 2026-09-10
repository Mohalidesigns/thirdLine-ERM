<?php

namespace App\Http\Requests\Dashboards;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/dashboards/{dashboard}/tabs/{tab}/widgets — place a widget.
 *
 * That the widget id belongs to this tenant is settled by the controller, which
 * resolves it through the tenant-scoped model rather than by an `exists` rule
 * here — a bare `exists:widget_definitions,id` would accept another
 * organisation's widget.
 */
class StoreDashboardWidgetRequest extends FormRequest
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
            'widget_id' => ['required', 'integer'],
        ];
    }
}
