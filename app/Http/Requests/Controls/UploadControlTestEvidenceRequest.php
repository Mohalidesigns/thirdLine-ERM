<?php

namespace App\Http\Requests\Controls;

use App\Models\ControlTest;
use App\Services\FileUploadService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attach a piece of evidence (risk.control-tests.upload-evidence). The file
 * rules are FileUploadService's, so the request and the storage-time check
 * cannot drift apart (WP-11).
 */
class UploadControlTestEvidenceRequest extends FormRequest
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
            'file' => app(FileUploadService::class)->rules(FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE),
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
