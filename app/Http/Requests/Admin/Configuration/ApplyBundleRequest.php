<?php

namespace App\Http\Requests\Admin\Configuration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply a bundle (migration Phase 6.6).
 *
 * `confirm` is `accepted` and stays that way: this is the operation that
 * rewrites the tenant's whole definition set in one transaction, and the
 * confirmation is the last thing between a mis-click and a configuration
 * nobody meant to have.
 *
 * `prune` is separate from `force` because they answer different questions.
 * `force` proceeds through a conflict — a row changed on both sides since the
 * last apply — and `prune` removes rows the bundle does not carry. Removals are
 * reported but never applied without it, which
 * `removals_are_reported_but_not_applied_without_prune` pins.
 */
class ApplyBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('apply', $this->route('bundle'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
            'prune' => ['sometimes', 'boolean'],
            'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Tick the confirmation before applying a bundle.',
        ];
    }
}
