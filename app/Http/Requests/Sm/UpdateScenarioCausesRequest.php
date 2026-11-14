<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Qualitative tab's cause checklist — `PUT {scenario}/causes` (spec 03 §2, §5.6).
 *
 * ITS OWN ROUTE, ITS OWN SAVE REGION. Spec 03 §5.6 gives this tab three writes
 * rather than one Save firing three requests, because "a partial failure stays
 * honest": one Save issuing three PUTs can succeed on causes and 422 on allocations,
 * and the truthful report is per-region. It also matches the workshop — causes are
 * ticked by the people in the room while allocation percentages are often negotiated
 * afterwards with finance, so one person's unfinished work must not hold the other's
 * hostage.
 *
 * THE BODY IS AN OBJECT KEYED BY CODE, not an array of `{code, note}`: an array would
 * make a deselect-and-reselect reorder the payload and turn `causes.{code}.note`
 * error keys into positional ones. Keyed by code, the payload is idempotent and every
 * error key is stable. An ABSENT CODE IS DESELECTED (§A1.4's sparse rows).
 *
 * `side` AND `cause_group` ARE NOT ACCEPTED. §A1.4: they are "derived from `code` by
 * the service at insert, never accepted from the request — a client posting them is
 * how a row arrives `side = external` with `code = people_other`." `CauseService`
 * derives both from `ScenarioCause::CHECKLIST`, so they have no rule here and cannot
 * reach `validated()`.
 */
class UpdateScenarioCausesRequest extends FormRequest
{
    /**
     * A cause note's length. Not specced, and set to match spec 02 §7.1's notes-log
     * body cap so this module has ONE idea of how long a note is rather than two
     * that drift. The column is `text`, so this is a product decision, not a schema
     * one — flagged in the Phase 1 handoff.
     */
    public const NOTE_MAX = 2000;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `present` rather than `required`: an EMPTY object is a legitimate post
            // — it means "untick everything", and refusing it would make the
            // checklist impossible to clear.
            'causes' => ['present', 'array'],
            'causes.*.note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            // Optional and defaulting true. §A1.4 gives `is_selected` a default of
            // true and says the column "exists so 'considered and rejected, see
            // note' is expressible"; spec 03 §2's body carries only `{note}`, so a
            // row is selected unless the caller says otherwise. Spec 03 §12.7 records
            // that Phase 1's UI never sends false — the capability is stored and
            // reachable only by a later phase or an importer.
            'causes.*.selected' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkCodesAreFrozen($validator);
                $this->checkRequiredNotes($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'causes.present' => 'No causes were sent.',
            'causes.array' => 'No causes were sent.',
            'causes.*.note.string' => 'A cause note must be text.',
            'causes.*.note.max' => 'Keep a cause note to 2 000 characters or fewer.',
            'causes.*.selected.boolean' => 'A cause is either selected or it is not.',
        ];
    }

    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }

    /**
     * The posted selection, keyed by frozen cause code.
     *
     * @return array<string, array{note: string|null, selected: bool}>
     */
    public function causes(): array
    {
        $out = [];

        foreach ((array) $this->validated()['causes'] as $code => $entry) {
            $entry = is_array($entry) ? $entry : ['note' => $entry];

            if (($entry['selected'] ?? true) === false) {
                continue;
            }

            $note = $entry['note'] ?? null;

            $out[(string) $code] = [
                'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
                'selected' => true,
            ];
        }

        return $out;
    }

    /**
     * §A1.8: "This list *is* the checklist; a code not in it is a 422."
     *
     * Array KEYS cannot be reached by a declarative rule, so the check is here. The
     * message is spec 03 §7's.
     */
    private function checkCodesAreFrozen(Validator $validator): void
    {
        foreach (array_keys((array) $this->input('causes', [])) as $code) {
            if (! array_key_exists((string) $code, ScenarioCause::CHECKLIST)) {
                $validator->errors()->add(
                    'causes.'.$code,
                    "That isn't one of this organisation's causes."
                );
            }
        }
    }

    /**
     * Addendum-3: "`requires_note` is config with no Phase 1 editing surface, so the
     * Form Request enforces 'note required when a `requires_note` cause is selected'
     * with spec 03 §7's message."
     *
     * CONFIG is the authority for the flag, not the const — a tenant that renames or
     * extends the checklist in Phase 5 edits config, and the rule follows. The four
     * "Other(s)" and "Unknown/yet to be determined" are the shipped five: their whole
     * content IS the note, and "Other" on its own tells a validator nothing.
     */
    private function checkRequiredNotes(Validator $validator): void
    {
        $requiresNote = [];

        foreach ((array) config('sm.causes.checklist', []) as $item) {
            if (($item['requires_note'] ?? false) === true) {
                $requiresNote[(string) $item['code']] = true;
            }
        }

        foreach ($this->causes() as $code => $entry) {
            if (! isset($requiresNote[$code]) || $entry['note'] !== null) {
                continue;
            }

            $validator->errors()->add(
                'causes.'.$code.'.note',
                "Say what the other cause is — 'Other' on its own tells a validator nothing."
            );
        }
    }
}
