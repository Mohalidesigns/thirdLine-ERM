<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST mfa/verify — the second factor, between password and session.
 *
 * AUTHORIZE IS TRUE ON PURPOSE. The caller has passed the password and has not
 * yet passed MFA, so there is nothing to authorise here: who they are is what
 * this request is about to establish. The controller resolves the pending user
 * from the session and returns to /login when there is none.
 */
class VerifyMfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }
}
