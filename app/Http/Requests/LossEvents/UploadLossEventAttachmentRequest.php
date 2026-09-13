<?php

namespace App\Http\Requests\LossEvents;

use App\Services\FileUploadService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/loss-events/{lossEvent}/attachments — evidence against a loss event.
 *
 * `is_regulatory` marks an attachment as part of what was filed with the
 * regulator, which is why it is captured at upload rather than inferred later:
 * after the fact nobody can tell which documents went to the CBN.
 */
class UploadLossEventAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lossEvent = $this->route('lossEvent');

        return $lossEvent !== null && ($this->user()?->can('update', $lossEvent) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => app(FileUploadService::class)->rules(FileUploadService::PROFILE_LOSS_EVENT_ATTACHMENT),
            'document_type' => ['nullable', 'string', 'max:50'],
            'is_regulatory' => ['nullable', 'boolean'],
        ];
    }
}
