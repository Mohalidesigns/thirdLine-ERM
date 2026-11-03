<?php

namespace App\Http\Requests\Kri;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Remind the contacts of unsubmitted returns (ADR 0026 section 16): body
 * `{return_ids: int[]}`, 1 to 50, distinct, tenant-bound. The route is guarded
 * by `kri.send_reminder`; each return's department scope is checked per return
 * by KriNoticeService and answered as `out_of_scope`, not a 403 for the batch.
 */
class SendKriReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('kri.send_reminder');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'return_ids' => ['required', 'array', 'min:1', 'max:50'],
            'return_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('kri_returns', 'id')->where('organization_id', TenantContext::organizationId()),
            ],
        ];
    }
}
