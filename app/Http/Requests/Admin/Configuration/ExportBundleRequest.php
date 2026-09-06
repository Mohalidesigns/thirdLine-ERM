<?php

namespace App\Http\Requests\Admin\Configuration;

use App\Models\ConfigBundle;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Export this organisation's configuration as a bundle (migration Phase 6.6).
 *
 * Re-exporting the same code produces a new VERSION rather than a clash, which
 * is why `code` has no uniqueness rule — `re_exporting_the_same_code_produces_a_new_version`
 * pins that, and adding a unique rule here would break it.
 */
class ExportBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ConfigBundle::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_\-]*$/'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
