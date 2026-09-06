<?php

namespace App\Http\Requests\Auth;

use App\Http\Controllers\Auth\NewPasswordController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST reset-password — set a new password from an emailed token.
 *
 * Unauthenticated by definition, so authorize() is true: the token is the
 * credential, and the controller answers an unknown address and a wrong token
 * identically so that neither can be used to enumerate accounts.
 */
class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            // One password policy for the whole product; see NewPasswordController::policy().
            'password' => ['required', 'confirmed', NewPasswordController::policy()],
        ];
    }
}
