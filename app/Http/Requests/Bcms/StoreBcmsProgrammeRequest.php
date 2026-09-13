<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The programme form (ISO 22301 clauses 4.1–4.3).
 *
 * `authorize()` asserts what actually guards the route (development standard
 * §3), so a route rewritten without the middleware fails here rather than
 * opening quietly.
 */
class StoreBcmsProgrammeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.programme.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // Clause 4.3 requires the scope to be documented. It is required
            // here rather than nullable because a programme with no scope
            // statement is a programme an auditor will open first.
            'scope_statement' => ['required', 'string', 'max:5000'],
            'out_of_scope_statement' => ['nullable', 'string', 'max:5000'],
            'interested_parties' => ['nullable', 'array'],
            'interested_parties.*' => ['string', 'max:200'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
