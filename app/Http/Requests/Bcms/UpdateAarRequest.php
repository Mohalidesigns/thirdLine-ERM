<?php

namespace App\Http\Requests\Bcms;

use App\Models\Bcms\Aar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a draft after-action report (aar-builder spec §4).
 *
 * ACCEPTS THE HUMAN-SUPPLIED FIELDS ONLY. `quantitative_results` and
 * `participant_feedback` are here because sections 4 and 6 are a
 * display-and-light-edit surface over system-computed JSON (spec §2) — the
 * computed keys are re-derived by `AarService::refreshComputedSections()` on
 * every read regardless of what a client sends, so a stale or malicious
 * payload cannot make a number the system itself measured disagree with
 * itself. `AarService::update()` is what actually refuses a write on a final
 * report; this request only shapes the payload.
 *
 * `participant_feedback` IS VALIDATED AGAINST THE PUBLISHED `bcms.aar.
 * feedback.v1` CONTRACT (clause map §2.3, `ndpa-register.md` §8.3), not as a
 * bare array. Comments carry role and business unit, never an identifiable
 * individual — `user_id`, `name` and `email` are explicitly `prohibited`
 * rather than merely unlisted, so a caller that names one gets a validation
 * error, not a silent drop. `AarService::update()` additionally normalises
 * to the contract shape, so a caller that never passes through this request
 * (an import, the AI drafter) cannot smuggle an identifier either.
 *
 * A POST-INCIDENT REVIEW ALSO NEEDS `bcms.incident.manage` (ADR 0020 §1,
 * `pir-post-incident-review.md` §1, Phase 10 Gate 1 finding): `bcms.aars.
 * update` is the one write route an exercise AAR and a PIR share, and
 * `bcms.aar.manage` alone says nothing about whether the caller may edit
 * incident-shaped evidence. `$this->route('aar')` is already the bound model
 * by the time `authorize()` runs — route model binding happens before the
 * container resolves this Form Request as a controller-method dependency.
 */
class UpdateAarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A single leading guard, not a repeated `$this->user()?->can(...)`
        // — PHPStan/Larastan resolves `FormRequest::user()`'s return as
        // `mixed`, and re-using the nullsafe operator on that same
        // expression a second time in one method is a documented corner
        // case here (see `AarService::conditions()`'s own condition 1 fix,
        // phase-9-notes.md §7 item 4) that reports the second use as
        // "never null" rather than as a real finding.
        $user = $this->user();

        if ($user === null || ! $user->can('bcms.aar.manage')) {
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
            'summary' => ['nullable', 'string', 'max:20000'],
            'what_worked' => ['nullable', 'string', 'max:20000'],
            'what_failed' => ['nullable', 'string', 'max:20000'],
            'quantitative_results' => ['nullable', 'array'],
            'participant_feedback' => ['nullable', 'array'],
            'participant_feedback.schema' => ['nullable', 'string', 'max:40'],
            'participant_feedback.invited' => ['nullable', 'integer', 'min:0'],
            'participant_feedback.responded' => ['nullable', 'integer', 'min:0'],
            'participant_feedback.questions' => ['nullable', 'array'],
            'participant_feedback.questions.*.key' => ['nullable', 'string', 'max:60'],
            'participant_feedback.questions.*.prompt' => ['nullable', 'string', 'max:500'],
            'participant_feedback.questions.*.scale' => ['nullable', 'string', 'in:1-5,yesno,text'],
            'participant_feedback.questions.*.distribution' => ['nullable', 'array'],
            'participant_feedback.questions.*.mean' => ['nullable', 'numeric'],
            'participant_feedback.comments' => ['nullable', 'array'],
            'participant_feedback.comments.*.text' => ['nullable', 'string', 'max:2000'],
            'participant_feedback.comments.*.role' => ['nullable', 'string', 'max:120'],
            'participant_feedback.comments.*.business_unit' => ['nullable', 'string', 'max:120'],
            // The contract's whole point: a comment names role and unit, never
            // a person. `prohibited` fails validation the moment the key is
            // present at all, rather than merely being unlisted — see the
            // class docblock.
            'participant_feedback.comments.*.user_id' => ['prohibited'],
            'participant_feedback.comments.*.name' => ['prohibited'],
            'participant_feedback.comments.*.email' => ['prohibited'],
            // Section 2's per-objective disposition (condition 6).
            'objective_disposition' => ['nullable', 'array'],
            'objective_disposition.*.objective_text' => ['required_with:objective_disposition', 'string', 'max:500'],
            'objective_disposition.*.note' => ['nullable', 'string', 'max:2000'],
            // Section 9's per-carried-action disposition (condition 9).
            'carried_action_disposition' => ['nullable', 'array'],
            'carried_action_disposition.*.reference' => ['required_with:carried_action_disposition', 'string', 'max:60'],
            'carried_action_disposition.*.disposition' => ['required_with:carried_action_disposition', 'string', 'in:validated,still_open,superseded'],
            'carried_action_disposition.*.note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
