<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\AccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Granting a named vendor person access — FR-ACC-02, CBN Cyber Appendix III
 * §1.3.
 *
 * `valid_to` IS REQUIRED, WITH NO ESCAPE HATCH. The framework's word is
 * "time-bounded", and an optional end date becomes an empty one on the busy
 * day — which is exactly the grant that turns up live four years later in a
 * reconciliation report. A relationship that genuinely needs standing access
 * sets a date and renews it, which is the review the requirement is asking for.
 *
 * `monitoring_method` is required for privileged access only. Asking how a
 * read-only reporting login is monitored produces "SIEM" typed by reflex;
 * asking it of an administrator makes somebody think.
 */
class StoreAccessGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.access.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $level = AccessLevel::tryFrom((string) $this->input('access_level'));
        $privileged = $level?->isPrivileged() ?? false;

        return [
            'grantee_name' => ['required', 'string', 'max:200'],
            'grantee_email' => ['nullable', 'email', 'max:255'],
            'system_name' => ['required', 'string', 'max:200'],
            'access_level' => ['required', Rule::in(array_column(AccessLevel::cases(), 'value'))],
            'justification' => ['required', 'string', 'max:2000'],
            // Tenant-scoped, and scoped to THIS engagement: a grant over a
            // connection belonging to a different relationship is a nonsense
            // that would sit in the register looking plausible.
            'connection_id' => [
                'nullable',
                'integer',
                Rule::exists('tp_connections', 'id')
                    ->where('organization_id', TenantContext::organizationIdOrNull())
                    ->where('engagement_id', $this->route('engagement')?->getKey()),
            ],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
            'escort_required' => ['boolean'],
            'monitoring_method' => [$privileged ? 'required' : 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_to.required' => 'Every grant needs an end date. Third-party access has to be time-bounded '
                .'(CBN Cyber Framework, Appendix III §1.3) — renew it if the work continues.',
            'monitoring_method.required' => 'Privileged access has to say how it is monitored.',
        ];
    }
}
