<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload an RCSA template (rcsa.imports.store).
 *
 * THE MIME TYPES ARE CHECKED, NOT THE EXTENSION. `mimes:` inspects the file's
 * actual content through the finfo extension, which matters here because
 * SpreadsheetReader decides its parser by sniffing too — a .xlsx renamed to
 * .csv fed to fgetcsv yields binary noise, and this product has shipped that
 * exact data-corruption path before (see SpreadsheetReader's docblock).
 *
 * `csv` is accepted alongside the spreadsheet formats even though the template
 * is .xlsx: a user exporting from another GRC tool gets a CSV, and refusing it
 * would send them to Excel to re-save a file this pipeline can already read.
 */
class StoreImportBatchRequest extends FormRequest
{
    /** 10 MB. A 1,000-row universe file is well under 1 MB; this is generous. */
    private const MAX_KILOBYTES = 10240;

    public function authorize(): bool
    {
        return $this->user()->can('import', RcsaRegisterRisk::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt',
                'max:'.self::MAX_KILOBYTES,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload an Excel workbook (.xlsx) or a CSV. Download the template if you do not have one.',
            'file.max' => 'That file is larger than 10 MB. Split it, or ask an administrator to raise the limit.',
        ];
    }
}
