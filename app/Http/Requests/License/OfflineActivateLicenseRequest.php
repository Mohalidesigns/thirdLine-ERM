<?php

namespace App\Http\Requests\License;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/license/offline-activate — activate from a signed licence file,
 * for a deployment with no outbound access to the licence server.
 *
 * The 1 MB cap is the point of the size rule: the file is a signed JWT, so
 * anything larger is not a licence, and JwtValidator should never be handed an
 * arbitrary upload to parse.
 */
class OfflineActivateLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('license.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'license_file' => ['required', 'file', 'max:1024'],
        ];
    }
}
