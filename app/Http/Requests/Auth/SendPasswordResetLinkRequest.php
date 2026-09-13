<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST forgot-password — request a reset link.
 *
 * Unauthenticated by definition. The controller's answer never reveals whether
 * the address exists, which is why the rule here stops at `email` and does not
 * add `exists:users` — that rule would turn the validation error itself into an
 * account-enumeration oracle.
 */
class SendPasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
