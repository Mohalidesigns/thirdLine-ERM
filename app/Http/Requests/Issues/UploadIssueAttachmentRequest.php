<?php

namespace App\Http\Requests\Issues;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/issues/{issue}/attachments.
 *
 * THE RULES ARE HAND-WRITTEN, AND THAT IS A DIVERGENCE, NOT A CHOICE.
 * FileUploadService holds a profile per upload kind — extension allowlist, MIME
 * check, size cap — precisely so upload points cannot drift apart, and the loss
 * event attachment endpoint next door uses one. There is no profile for issue
 * attachments, so this endpoint accepts a set nothing else does: `zip`, `msg`
 * and `eml` at 10 MB.
 *
 * Reproduced verbatim on purpose. Moving it onto a profile changes what the
 * endpoint accepts, which is a behaviour change that belongs in its own commit
 * with its own test, not folded into a Form Request extraction. Recorded in
 * docs/migration/phase-7-notes/hardening.md.
 */
class UploadIssueAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue !== null && ($this->user()?->can('update', $issue) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,png,jpg,jpeg,txt,msg,eml,zip'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'is_regulatory' => ['nullable', 'boolean'],
        ];
    }
}
