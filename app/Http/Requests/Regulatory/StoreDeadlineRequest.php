<?php

namespace App\Http\Requests\Regulatory;

use App\Models\RegulatoryDeadline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Add a filing deadline to the calendar (migration Phase 5.3).
 *
 * `responsible_id` HAD NO RULE AT ALL. The controller validated five fields
 * and then wrote `'responsible_id' => $request->responsible_id` straight into
 * the row, so any user id in the request body was accepted — including one
 * belonging to another institution on the same installation. The deadline
 * screens then rendered that person's name as the officer accountable for a
 * CBN return. The tenant-bound Rule::exists below is the fix, and it is the
 * shape every Form Request in this programme uses for a foreign key: never the
 * string `exists:users,id`, which asks only whether the row exists.
 */
class StoreDeadlineRequest extends FormRequest
{
    /** The filing cadences the register recognises. */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annual', 'ad_hoc'];

    public function authorize(): bool
    {
        return $this->user()->can('create', RegulatoryDeadline::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'regulator' => ['required', 'string', 'max:50'],
            'report_type' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'deadline_date' => ['required', 'date'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ];
    }
}
