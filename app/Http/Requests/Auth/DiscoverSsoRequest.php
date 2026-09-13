<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST auth/sso/discover — is this email address federated?
 *
 * Unauthenticated by definition. `string` rather than `email`: the controller
 * is deliberately vague about whether a domain is federated, because that is
 * information about a customer, and a stricter rule here would let a caller
 * distinguish "not an email" from "not federated" for free.
 */
class DiscoverSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
        ];
    }
}
