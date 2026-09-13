<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\ConnectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Recording a technical connection — FR-ACC-01.
 *
 * `encryption` AND `authentication_method` ARE REQUIRED FOR NETWORK PATHS and
 * optional otherwise. A courier bringing a tape has neither, and demanding
 * them would teach people to type "n/a"; an API with neither recorded is a
 * gap, and the moment to notice it is while somebody is still looking at the
 * form rather than during an examination two years later.
 */
class StoreConnectionRequest extends FormRequest
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
        $type = ConnectionType::tryFrom((string) $this->input('type'));
        $isNetwork = $type?->isNetworkPath() ?? false;

        return [
            'type' => ['required', Rule::in(array_column(ConnectionType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:200'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'direction' => ['required', Rule::in(['inbound', 'outbound', 'bidirectional'])],
            'data_flows' => ['nullable', 'string', 'max:2000'],
            'encryption' => [$isNetwork ? 'required' : 'nullable', 'string', 'max:120'],
            'authentication_method' => [$isNetwork ? 'required' : 'nullable', 'string', 'max:120'],
            'firewall_rule_ref' => ['nullable', 'string', 'max:120'],
            /*
             * Tenant-scoped, not a bare `exists`. A bare one accepts any id in
             * the table, which on a shared deployment means one tenant can
             * name another tenant's user as the owner of its connection.
             */
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', TenantContext::organizationIdOrNull()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'encryption.required' => 'Say how this path is encrypted. A network connection with no recorded '
                .'encryption is what an examiner opens the file to find.',
            'authentication_method.required' => 'Say how the far end authenticates.',
        ];
    }
}
