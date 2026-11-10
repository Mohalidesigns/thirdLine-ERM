<?php

namespace App\Http\Requests\Sm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The linked-risk type-ahead — `GET risk-search` (spec 02 §5.7).
 *
 * AN ON-DEMAND ENDPOINT, NOT A SHIPPED LIST. "A register is thousands of rows and
 * shipping it would cost 60 KB+ — five seconds at 100 kbps for a list most users never
 * open." Nothing is fetched until the user types, the query must be at least two
 * characters, and results are capped at twenty. Spec 01 §4 makes `urls.riskSearch` a
 * SHELL prop rather than an Overview-tab one so a later phase can search from any tab.
 *
 * A Form Request rather than `$request->validate()` in the controller: standard §4 keeps
 * validation out of controllers, and `grep -rn "validate(\[" app/Http/Controllers` must
 * stay empty.
 */
class SearchRisksRequest extends FormRequest
{
    /** Spec 02 §5.7: "Query `urls.riskSearch` on ≥ 2 characters". */
    public const MINIMUM_TERM = 2;

    /** Spec 02 §5.7: "results capped at 20". */
    public const RESULT_CAP = 20;

    public function authorize(): bool
    {
        // The route carries `permission:scenario.view`. Reading the register itself is
        // a separate question, answered per row by `Risk::visibleTo()` and by nulling
        // `url` for a caller without `risk.view` — so the search is available to
        // everyone who can open the workspace, and leaks nothing they may not see.
        return $this->user()?->can('scenario.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.self::MINIMUM_TERM, 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.required' => 'Type at least two characters to search the register.',
            'q.min' => 'Type at least two characters to search the register.',
            'q.max' => 'Keep the search to 100 characters or fewer.',
            'q.string' => 'Type at least two characters to search the register.',
        ];
    }

    /** The trimmed search term. */
    public function term(): string
    {
        return trim((string) $this->validated()['q']);
    }
}
