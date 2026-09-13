<?php

namespace App\Http\Requests\Grid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/grids/{grid}/bulk/{action} — act on a selection, or on everything
 * the current filters match.
 *
 * `all` is the dangerous half: it means "every row this query returns", which
 * on an unfiltered register is the whole register. The controller re-runs the
 * grid's own scoped query rather than trusting a client-supplied count, so the
 * blast radius is whatever the caller could already see.
 */
class BulkGridActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['array'],
            'ids.*' => ['string'],
            'all' => ['nullable', 'boolean'],
        ];
    }
}
