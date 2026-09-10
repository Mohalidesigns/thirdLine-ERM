<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST mfa/enable — confirm enrolment by proving the authenticator works.
 *
 * `digits:6` rather than `size:6`: a TOTP code is six DIGITS, and a string rule
 * would accept "12345a" and hand it to the verifier as a near-miss.
 */
class ConfirmMfaEnrolmentRequest extends FormRequest
{
    /**
     * The route sits behind `auth` — the user is signed in and enrolling their
     * own second factor. There is no other subject to authorise against.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }
}
