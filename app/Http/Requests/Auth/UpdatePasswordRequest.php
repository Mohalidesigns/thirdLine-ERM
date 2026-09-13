<?php

namespace App\Http\Requests\Auth;

use App\Http\Controllers\Auth\NewPasswordController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT password — change your own password while signed in.
 *
 * `current_password` is the rule that makes this safe: without it a stolen
 * session could set a new password without knowing the old one, which turns a
 * hijacked session into a permanent account takeover.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', NewPasswordController::policy()],
        ];
    }
}
