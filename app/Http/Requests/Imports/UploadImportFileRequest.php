<?php

namespace App\Http\Requests\Imports;

use App\Services\FileUploadService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/imports — upload a spreadsheet for import.
 *
 * The file rules come from FileUploadService rather than being written here.
 * That service holds one profile per upload kind — extension allowlist, MIME
 * check, size cap — so that a new upload point cannot quietly accept something
 * the others refuse. Resolved from the container because a Form Request has no
 * constructor injection of its own.
 */
class UploadImportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('import.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => app(FileUploadService::class)->rules(FileUploadService::PROFILE_DATA_IMPORT),
            'import_type' => ['required', 'in:risks,controls,loss_events,issues,kris'],
        ];
    }
}
