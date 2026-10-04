<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Approve or reject one staged directory change — ADR 0018 §2.4, §7.
 *
 * `bcms.identity.review`, NOT `bcms.identity.manage`. Deciding that Musa
 * Bello has left and needs re-parenting is day-to-day continuity work; the
 * two permissions are deliberately split (ADR 0018 §7).
 */
class DecideIdentitySyncChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.identity.review') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
        ];
    }
}
