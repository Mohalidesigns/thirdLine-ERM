<?php

namespace App\Http\Requests\Widgets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The context a widget is rendered in: which node, which filters, which
 * overrides.
 *
 * ONE CLASS FOR BOTH ENDPOINTS, because both go through the same private
 * render() helper. `payload` returns JSON to the dashboard and `export` streams
 * the same data as CSV, so a rule that applied to one and not the other would
 * mean the spreadsheet could be asked for something the screen could not.
 *
 * `filters` and `overrides` are open arrays because a widget DEFINITION decides
 * what it accepts; the resolver validates them against that definition, which
 * is a check no static rule set here could make.
 */
class WidgetContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'node' => ['nullable', 'integer'],
            'filters' => ['nullable', 'array'],
            'overrides' => ['nullable', 'array'],
        ];
    }
}
