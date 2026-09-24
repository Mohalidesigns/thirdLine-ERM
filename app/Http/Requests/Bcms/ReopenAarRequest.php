<?php

namespace App\Http\Requests\Bcms;

use App\Models\Bcms\Aar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopening a final after-action report (clause map refinement 7, ADR 0019
 * §2). The reason is mandatory — it lands as a `system` timeline entry, which
 * is the only place the "why" of a reopen is ever recorded.
 *
 * A POST-INCIDENT REVIEW ALSO NEEDS `bcms.incident.manage`, the same
 * conjunction `UpdateAarRequest` requires and for the same reason (ADR 0020
 * §1, `pir-post-incident-review.md` §1, Phase 10 Gate 1 finding) — reopening
 * shares this write route with the exercise AAR, and `bcms.aar.approve` alone
 * says nothing about incident-shaped evidence.
 */
class ReopenAarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // See `UpdateAarRequest::authorize()` for why this is a single
        // leading guard rather than a repeated nullsafe call.
        $user = $this->user();

        if ($user === null || ! $user->can('bcms.aar.approve')) {
            return false;
        }

        $aar = $this->route('aar');

        if ($aar instanceof Aar && $aar->isPostIncident()) {
            return $user->can('bcms.incident.manage');
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
