<?php

namespace App\Http\Requests\License;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/license/activate — activate this deployment with a licence key.
 *
 * A licence binds the whole DEPLOYMENT rather than one organisation, which is
 * why the permission is license.manage and why the seeder gives it to
 * super-admin alone.
 */
class ActivateLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('license.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string', 'min:10'],
        ];
    }
}
