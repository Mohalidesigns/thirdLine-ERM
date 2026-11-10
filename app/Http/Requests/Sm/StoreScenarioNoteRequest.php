<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Note;
use App\Models\Sm\Scenario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Append to the notes log — `POST {scenario}/notes` (spec 02 §7, §A1.7).
 *
 * STORE-ONLY. There is no update and no destroy route in Phase 1, no `update`/`delete`
 * ability on `NotePolicy`, and no rule here that could serve one: "a corrected note is a
 * new note. This is the bias-mitigation trail D4 rests on, and a dissent its author can
 * quietly edit is not a dissent trail."
 *
 * ── `scenario.note`, NOT `scenario.update` ────────────────────────────────────
 *
 * §A1.10 splits them because they are different authorities: editing a scenario is
 * first-line work, recording a dissent against somebody else's is second-line work, and
 * one permission for both lets the designer write the dissent log the trail exists to
 * keep. Per §A1.10's grants, `risk-analyst`, `risk-owner` and `compliance-officer` hold
 * `scenario.view` + `scenario.note` ONLY — so a risk owner can open a scenario they
 * cannot edit and dissent against it. Spec 01 §5.5's table is the user-visible half: the
 * Notes column is gated on `can.note`, and the one status where notes close is
 * `archived`.
 *
 * ── IDENTITY IS NOT A FIELD ───────────────────────────────────────────────────
 *
 * Spec 02 §7.1: "The author is the authenticated user and the timestamp is the server's.
 * A note whose author can be typed is not evidence." So there is no `author_id` or
 * `author_name` rule here — the controller passes the authenticated user and
 * `ScenarioLifecycle::addNote()` snapshots the name, because "an anonymous dissent cannot
 * be followed up, which is worse than none".
 *
 * ── THE `archived` REFUSAL IS NOT HERE EITHER ─────────────────────────────────
 *
 * "Notes close when the scenario is archived" is a LIFECYCLE rule, and standard §3 keeps
 * those in the controller answering with a flash rather than a 403.
 */
class StoreScenarioNoteRequest extends FormRequest
{
    /** Spec 02 §7.1: the body textarea is `maxlength` 2000. */
    public const BODY_MAX = 2000;

    public function authorize(): bool
    {
        return $this->user()?->can('note', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The four frozen kinds (§A1.7). `kind`, not `type`: §A1.7 froze the COLUMN
            // spelling and addendum-4 says the presenter does no renaming, so the wire
            // key, the 422 key, the prop and the column are one word.
            'kind' => ['required', 'string', Rule::in(Note::KINDS)],

            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
        ];
    }

    /**
     * Verbatim from spec 02 §7.1.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Choose what kind of note this is.',
            'kind.in' => 'Choose what kind of note this is.',
            'kind.string' => 'Choose what kind of note this is.',

            'body.required' => 'Write the note before adding it.',
            'body.string' => 'Write the note before adding it.',
            'body.max' => 'Keep a note to 2 000 characters or fewer.',
        ];
    }

    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }
}
