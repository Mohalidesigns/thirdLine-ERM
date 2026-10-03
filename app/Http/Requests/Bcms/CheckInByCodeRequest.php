<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The SMS/short-code fallback check-in (qr-checkin spec) — unauthenticated,
 * like the token path it stands in for. `authorize()` is `true`: there is no
 * session and no permission to check here, only a code the service itself
 * verifies with `hash_equals` (`CheckInService::participantForShortCode()`).
 */
class CheckInByCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16'],
        ];
    }
}
