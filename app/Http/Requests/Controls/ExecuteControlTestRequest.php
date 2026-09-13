<?php

namespace App\Http\Requests\Controls;

use App\Models\ControlTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Record a result against an in-progress test (risk.control-tests.complete). */
class ExecuteControlTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $test = $this->route('controlTest');

        return $test instanceof ControlTest && $this->user()->can('execute', $test);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(ControlTest::RESULTS)],
            'findings' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
