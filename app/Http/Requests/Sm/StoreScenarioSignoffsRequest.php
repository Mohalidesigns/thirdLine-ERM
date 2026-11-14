<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\Signoff;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The sign-off modal's FIRST of two writes — `POST {scenario}/signoffs` (spec 01
 * §5.6, §A1.6).
 *
 * ── RECORDING AND MOVING THE STATUS ARE TWO WRITES ────────────────────────────
 *
 * Spec 01 §5.6.7–§5.6.9: §A1.8's guard on `validated → signed_off` requires ≥1 CURRENT
 * sign-off with `decision = approved`, so the rows must exist BEFORE the status can
 * move. This endpoint only appends rows; `POST {scenario}/status` is a separate call
 * the modal makes afterwards. Spec 01 §5.6.8 is explicit that "recording a sign-off and
 * moving the status are genuinely separate acts, and a signer who is not the person
 * that moves the status is a normal case, not an error" — so nothing here transitions
 * anything.
 *
 * ── THE ROWS ARE APPENDED, NEVER UPSERTED ─────────────────────────────────────
 *
 * §A1.6 puts NO unique key on (sm_scenario_id, source) precisely so source 1 can sign,
 * be returned, and sign AGAIN without an UPDATE over the record the table exists to
 * protect. Re-signing after a revalidation therefore appends; `Scenario::currentSignoffs()`
 * decides which row per source is live.
 *
 * ── WHAT IS *NOT* VALIDATED HERE ──────────────────────────────────────────────
 *
 * The independent-challenge requirement and the author-self-challenge rule are DOMAIN
 * rules, not validation rules:
 *
 *   - "≥1 independent challenge" is part of the `validated → signed_off` guard
 *     (`ScenarioLifecycle::assertSignOffAllowed()`), because it is a property of the
 *     whole current sign-off set — a Source 2 who signed yesterday may already satisfy
 *     it, and refusing to RECORD a legitimate Source 1 row over it would throw away
 *     evidence to police a checkbox.
 *   - C1.5 (an author cannot be their own independent challenge) forces the flag false
 *     WITH A FLASH, not a 422, because "the sign-off itself is legitimate and only the
 *     claim of independence is refused".
 */
class StoreScenarioSignoffsRequest extends FormRequest
{
    /** Spec 01 §5.6.6's comment field. `text` on the column; 2000 matches the notes log. */
    public const COMMENT_MAX = 2000;

    public function authorize(): bool
    {
        return $this->user()?->can('signOff', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            // Spec 01 §5.6.2: "At least Source 1 is required. A row left entirely empty
            // is not posted." So an empty array is a refusal, and Source 1's presence is
            // checked in after() because a rule cannot express "one of these keys".
            'signoffs' => ['required', 'array', 'min:1', 'max:3'],

            'signoffs.*.source' => ['required', 'string', Rule::in(array_keys(Signoff::SOURCES))],

            // SNAPSHOTS, and required as TEXT — spec 01 §4.7: the modal "must collect a
            // name and a role as text, not merely pick a user". §A1.6 froze both NOT NULL
            // with `user_id` nullable, because a sign-off whose signatory has left must
            // still say who signed it. Widths are the columns' (§A1.6, ADR 0014).
            'signoffs.*.signatory_name' => ['required', 'string', 'max:200'],

            // A FREE-TEXT CAPACITY, never a platform role name: §A1.6 refuses Spatie
            // names because renaming one "would silently falsify every historical
            // sign-off storing it". So there is no Rule::in here and must never be one.
            'signoffs.*.role' => ['required', 'string', 'max:100'],

            // Nullable and tenant-bound: an external committee member or a group CRO
            // without an account can still sign, and the snapshot is the evidence.
            'signoffs.*.user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],

            // TWO values (§A1.6). `approved_with_conditions` was refused: no transition
            // branches on it, and a condition is a notes row of kind `assumption`. A
            // third value nothing reads is how a workflow acquires a state nobody can
            // leave. A signer who declines without returning the scenario records a
            // DISSENT NOTE instead — the coordinator's Phase 1 ruling.
            'signoffs.*.decision' => ['required', 'string', Rule::in(Scenario::DECISIONS)],

            'signoffs.*.is_independent_challenge' => ['sometimes', 'boolean'],
            'signoffs.*.comment' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],

            // Spec 01 §5.6.4 makes this required in the modal, and C1.2 makes the
            // SERVER refuse `validated → signed_off` without it: "a requirement that
            // exists only in JSX is not a requirement — a non-JS 422, a stale tab or a
            // Phase 5 import bypasses it". Collecting it here is what makes the guard
            // satisfiable in one flow.
            'review_due_on' => ['required', 'date_format:Y-m-d'],

            // One comment for the whole modal in spec 01 §5.6.6, applied to any row that
            // does not carry its own. Both spellings are accepted so the screen cannot
            // lose a signer's words to a shape question.
            'comment' => ['nullable', 'string', 'max:'.self::COMMENT_MAX],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkSource1IsPresent($validator);
                $this->checkSourcesAreNotRepeated($validator);
            },
        ];
    }

    /**
     * 422 keys per spec 01 §5.6: `signoffs.{i}.signatory_name`,
     * `signoffs.{i}.role`, `signoffs.{i}.user_id`, `review_due_on`, `comment`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signoffs.required' => 'Record at least one sign-off.',
            'signoffs.array' => 'Record at least one sign-off.',
            'signoffs.min' => 'Record at least one sign-off.',
            'signoffs.max' => 'A scenario has three sign-off sources, not more.',

            'signoffs.*.source.required' => 'Choose which source this sign-off is for.',
            'signoffs.*.source.in' => 'Choose which source this sign-off is for.',

            'signoffs.*.signatory_name.required' => "Enter the signatory's name.",
            'signoffs.*.signatory_name.max' => 'Keep the signatory name to 200 characters or fewer.',

            'signoffs.*.role.required' => "Enter the signer's role or capacity.",
            'signoffs.*.role.max' => 'Keep the role to 100 characters or fewer.',

            'signoffs.*.user_id.exists' => "That signer isn't in your organisation.",
            'signoffs.*.user_id.integer' => "That signer isn't in your organisation.",

            'signoffs.*.decision.required' => 'Choose a decision for this signer.',
            'signoffs.*.decision.in' => 'Choose a decision for this signer.',

            'signoffs.*.is_independent_challenge.boolean' => 'Independence is either claimed or it is not.',
            'signoffs.*.comment.max' => 'Keep a comment to 2 000 characters or fewer.',

            // Spec 02 §5.9's wording, which C1.2's guard uses verbatim too.
            'review_due_on.required' => 'Set the next review date before signing off.',
            'review_due_on.date_format' => 'Enter a real date.',

            'comment.max' => 'Keep a comment to 2 000 characters or fewer.',
        ];
    }

    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }

    /**
     * The posted rows, normalised for `ScenarioLifecycle::recordSignoffs()`.
     *
     * @return list<array<string, mixed>>
     */
    public function signoffRows(): array
    {
        $shared = $this->input('comment');
        $shared = is_string($shared) && trim($shared) !== '' ? trim($shared) : null;

        $rows = [];

        foreach ((array) $this->validated()['signoffs'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $comment = isset($row['comment']) && trim((string) $row['comment']) !== ''
                ? trim((string) $row['comment'])
                : $shared;

            $rows[] = [
                'source' => (string) $row['source'],
                'user_id' => isset($row['user_id']) && $row['user_id'] !== '' ? (int) $row['user_id'] : null,
                'signatory_name' => trim((string) $row['signatory_name']),
                'role' => trim((string) $row['role']),
                'decision' => (string) $row['decision'],
                'is_independent_challenge' => (bool) ($row['is_independent_challenge'] ?? false),
                'comment' => $comment,
            ];
        }

        return $rows;
    }

    /**
     * Spec 01 §5.6.2: "At least Source 1 is required."
     *
     * Source 1 is the workbook's first signature block; a scenario signed only by
     * sources 2 and 3 has nobody accountable for the design itself.
     */
    private function checkSource1IsPresent(Validator $validator): void
    {
        $sources = [];

        foreach ((array) $this->input('signoffs', []) as $row) {
            if (is_array($row) && isset($row['source'])) {
                $sources[] = (string) $row['source'];
            }
        }

        if ($sources !== [] && ! in_array('source_1', $sources, true)) {
            $validator->errors()->add(
                'signoffs',
                'Source 1 must sign before this scenario can be signed off.'
            );
        }
    }

    /**
     * One row per source PER SUBMISSION.
     *
     * Appending two Source 1 rows in one post is a client defect, not a re-signing:
     * §A1.6's append-only model exists so a SECOND ROUND supersedes a first, and a
     * double-click producing two simultaneous rows for one source would make "latest by
     * `signed_at`" a coin toss inside the same second. Not specced — flagged in the
     * Phase 1 handoff.
     */
    private function checkSourcesAreNotRepeated(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('signoffs', []) as $index => $row) {
            if (! is_array($row) || ! isset($row['source'])) {
                continue;
            }

            $source = (string) $row['source'];

            if (! isset($seen[$source])) {
                $seen[$source] = $index;

                continue;
            }

            $validator->errors()->add(
                "signoffs.{$index}.source",
                'Each source signs once per submission.'
            );
        }
    }
}
