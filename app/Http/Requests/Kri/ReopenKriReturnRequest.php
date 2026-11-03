<?php

namespace App\Http\Requests\Kri;

use App\Models\KriReturn;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopen a submitted return. Champion only; "is it submitted" and "is the
 * period closed" are lifecycle rules the controller answers with a flash.
 */
class ReopenKriReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $return = $this->route('return');

        return $return instanceof KriReturn && $this->user()->can('reopen', $return);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
