<?php

namespace App\Http\Requests\Rcsa;

use App\Services\Rcsa\RcsaImportPublisher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publish a staged batch into the universe (rcsa.imports.publish).
 *
 * `valid_only` is the explicit choice §7.2 requires: publishing is blocked
 * while any row is in error UNLESS the user says, in so many words, to publish
 * the valid rows and leave the rest. It defaults to false, so the safe path is
 * the one you get by not thinking about it.
 *
 * Authorised on `rcsa_universe.publish`, NOT on `import`. Uploading a file and
 * approving what it does to the master data are different acts — a risk
 * champion may well be trusted to prepare a spreadsheet and not to publish it
 * into every future assessment. §14 Q8 is the same question for the screen.
 */
class PublishImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rcsa_universe.publish');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in([
                RcsaImportPublisher::MODE_CREATE,
                RcsaImportPublisher::MODE_UPDATE,
                RcsaImportPublisher::MODE_CREATE_UPDATE,
            ])],
            'valid_only' => ['nullable', 'boolean'],
        ];
    }
}
