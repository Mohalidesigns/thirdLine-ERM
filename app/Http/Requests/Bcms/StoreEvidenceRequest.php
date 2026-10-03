<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Evidence;
use App\Services\FileUploadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading an evidence artefact against an occurrence, a score, a readiness
 * task or the AAR itself (ADR 0019).
 *
 * THE FILE RULES COME FROM `FileUploadService`, NOT RESTATED HERE — one
 * profile, one definition, shared by this request and the store (standard's
 * own "one `rules()` definition" discipline).
 *
 * UPLOAD REQUIRES FACILITATE OR EVALUATE — the two roles actually present at
 * an exercise (ADR 0019 §4). `authorize()` cannot see the target occurrence's
 * organisation before the route model binder has already refused an
 * out-of-scope one, so this only checks the ability; the controller still
 * resolves the occurrence through `BindsToVisibleRecord`.
 */
class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true
            || $this->user()?->can('bcms.exercise.evaluate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => app(FileUploadService::class)->rules(FileUploadService::PROFILE_BCMS_EVIDENCE),
            'owner_type' => ['required', Rule::in(Evidence::ownerKinds())],
            'owner_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['photo', 'file', 'screenshot'])],
            'caption' => ['nullable', 'string', 'max:255'],
            // DoD: "every evidence-bearing artefact carries an iso_clause_ref."
            // A caller MAY name one — never a free string, always a member of
            // the one published taxonomy (App\Enums\Bcms\IsoClauseRef). When
            // none is supplied, `EvidenceService::upload()` derives one from
            // `owner_type` per the clause map (§1.2) rather than leaving the
            // row unstamped.
            'iso_clause_ref' => ['nullable', Rule::enum(IsoClauseRef::class)],
        ];
    }
}
