<?php

namespace App\Http\Requests\Grid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH risk/grids/{grid}/cell — inline edit of one cell.
 *
 * `id` is deliberately untyped: a grid's row key is whatever its definition
 * says it is, and the definition — not this rule — decides whether the row is
 * in the caller's tenant and whether the column may be written at all.
 *
 * Authorisation is the route's `can:view-grid,grid`, which resolves the grid by
 * name and asks the definition's own permission. It cannot be repeated here
 * without resolving the registry a second time and disagreeing with it.
 */
class UpdateGridCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required'],
            'key' => ['required', 'string'],
            'value' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
