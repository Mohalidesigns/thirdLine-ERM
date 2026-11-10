<?php

namespace App\Http\Controllers\Sm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sm\StoreScenarioNoteRequest;
use App\Http\Requests\Sm\StoreScenarioSignoffsRequest;
use App\Http\Requests\Sm\TransitionScenarioRequest;
use App\Models\Sm\Scenario;
use App\Services\Sm\ScenarioLifecycle;
use App\Services\Sm\ScenarioRefused;
use Illuminate\Http\RedirectResponse;

/**
 * The three governance writes: the lifecycle, the sign-offs and the notes log
 * (ADR 0027 §A1.6–§A1.8, spec 01 §5).
 *
 * SEPARATE FROM `ScenarioController` BECAUSE THEY ANSWER TO THREE DIFFERENT
 * PERMISSIONS — `scenario.validate`, `scenario.sign_off` and `scenario.note` — and §A1.10
 * keeps those authorities apart deliberately. `scenario.note` in particular is not
 * `scenario.update`: editing a scenario is first-line work, recording a dissent against
 * somebody else's is second-line work, and one permission for both lets the designer write
 * the dissent log the trail exists to keep. Per §A1.10's grants `risk-analyst`, `risk-owner`
 * and `compliance-officer` hold view + note ONLY, so a risk owner can open a scenario they
 * cannot edit and dissent against it.
 *
 * EVERY REFUSAL HERE IS A FLASH, NOT A 403 AND NOT A 422. Standard §3 and §A1.8: the user is
 * allowed to try, the machine says no and says why. `ScenarioRefused` carries the sentence,
 * so the rule lives in the service where a test can reach it without HTTP and the wording
 * cannot drift between the two. The 422s this module does produce are the FORM ones —
 * `TransitionScenarioRequest` turns a failed `designed` gate into spec 03 §7's messages on
 * the frozen keys, because §A1.5 says the allocation invariant "answers 422 on key
 * `allocations`" at that transition and spec 03 §7 says those strings "must be the Form
 * Request's messages, not client-side paraphrases".
 */
class ScenarioGovernanceController extends Controller
{
    public function __construct(private readonly ScenarioLifecycle $lifecycle) {}

    /**
     * Every lifecycle transition — `POST {scenario}/status` (spec 01 §5.2).
     *
     * ONE ROUTE, FOURTEEN EDGES, ONE PERMISSION. §A1.11 gates it with `scenario.validate`
     * and spec 01 §3 says so twice: every move rides it, INCLUDING `draft → in_design`. There
     * is no per-transition permission and `scenario.design` does not exist in Phase 1 (§A1.10
     * defers it to Phase 2). The client holds no state machine: `transitions` was filtered by
     * permission AND status on the way out, and `to` is checked against
     * `Scenario::STATUS_TRANSITIONS` on the way in.
     *
     * THE REASON AND THE STATUS CHANGE ARE ONE TRANSACTION (C1.3): `reason` is required when
     * `to` is `returned_for_rework` and `ScenarioLifecycle::transition()` writes the
     * `challenge` note inside the same DB transaction, because "a return whose reason landed
     * in a separate write is a return with no recorded reason after any failure between the
     * two" — and the trail D4 rests on would have a hole in it.
     */
    public function status(TransitionScenarioRequest $request, Scenario $scenario): RedirectResponse
    {
        $from = $scenario->status;
        $to = (string) $request->validated()['to'];

        try {
            $scenario = $this->lifecycle->transition($scenario, $to, $request->user(), [
                'reason' => $request->validated()['reason'] ?? null,
            ]);
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', $this->lifecycle->successMessage($scenario, $from, $to));
    }

    /**
     * The sign-off modal's FIRST of two writes — `POST {scenario}/signoffs` (spec 01 §5.6).
     *
     * APPENDS ROWS AND NOTHING ELSE. §A1.8's guard on `validated → signed_off` requires ≥1
     * CURRENT sign-off with `decision = approved`, so the rows must exist before the status can
     * move; the modal then posts `urls.status` as its second, visible step. Spec 01 §5.6.8 is
     * explicit that "recording a sign-off and moving the status are genuinely separate acts, and
     * a signer who is not the person that moves the status is a normal case, not an error" — so
     * a partial flow is a legitimate state, not a failure, and §5.6.9 tells the user how to
     * finish it rather than rolling back behind their back.
     *
     * C1.5 rides along here: an author signing their own scenario gets
     * `is_independent_challenge` forced false WITH A FLASH, because the sign-off itself is
     * legitimate and only the claim of independence is refused. A 422 would throw away a real
     * approval to police a checkbox.
     */
    public function storeSignoffs(StoreScenarioSignoffsRequest $request, Scenario $scenario): RedirectResponse
    {
        $result = $this->lifecycle->recordSignoffs(
            $scenario,
            $request->signoffRows(),
            $request->user(),
            (string) $request->validated()['review_due_on']
        );

        $redirect = back()->with('success', $this->signoffFlash($result['signoffs'], $scenario));

        if ($result['flashes'] !== []) {
            // `warning`, not `error`: the sign-off LANDED, and only the independence claim
            // was refused. An error banner would tell a signer their approval was rejected
            // when the row is on the trail and counted. Joined rather than flashed in a loop
            // because a session key holds one value — three authors claiming independence
            // would otherwise leave only the last one visible.
            $redirect = $redirect->with('warning', implode(' ', $result['flashes']));
        }

        return $redirect;
    }

    /**
     * Append to the notes log — `POST {scenario}/notes` (spec 02 §7, §A1.7).
     *
     * STORE-ONLY. There is no update route, no destroy route and no policy ability for either:
     * "a corrected note is a new note. This is the bias-mitigation trail D4 rests on, and a
     * dissent its author can quietly edit is not a dissent trail."
     *
     * IDENTITY IS NOT A FIELD. The author is the authenticated user and the timestamp is the
     * server's; `author_name` is snapshotted because "an anonymous dissent cannot be followed
     * up, which is worse than none". A note whose author could be typed would not be evidence.
     *
     * Notes stay open in EVERY status except `archived`, including `signed_off` — "a note is
     * commentary, not a change" (spec 01 §5.5). That is also what makes addendum-1's snapshot
     * discipline necessary: a challenge note appended a month after sign-off must not silently
     * rewrite what was outstanding when the signer signed.
     */
    public function storeNotes(StoreScenarioNoteRequest $request, Scenario $scenario): RedirectResponse
    {
        // A lifecycle rule, so a flash rather than a 403 (standard §3). Spec 01 §5.5's table
        // closes the notes log at `archived` alone: the record is out of circulation, and a
        // comment nobody will read is worse than no comment box.
        if ($scenario->status === 'archived') {
            return back()->with('error', 'This scenario is archived. Notes can no longer be added to it.');
        }

        $validated = $request->validated();

        $this->lifecycle->addNote(
            $scenario,
            (string) $validated['kind'],
            (string) $validated['body'],
            $request->user()
        );

        return back()->with('success', 'Note added.');
    }

    /**
     * The success sentence for a recorded sign-off set.
     *
     * Names how many sources signed and what the position now is, WITHOUT asserting a status
     * change this endpoint did not make — spec 01 §5.6.8 leaves that to the second write, and a
     * flash claiming "signed off" here would be the first lie in a flow the specs go out of
     * their way to keep two-step.
     *
     * @param  array<int, \App\Models\Sm\Signoff>  $signoffs
     */
    private function signoffFlash(array $signoffs, Scenario $scenario): string
    {
        $count = count($signoffs);

        $returned = array_filter($signoffs, fn ($row) => $row->decision === 'returned_for_rework');

        if ($returned !== []) {
            return sprintf(
                '%s recorded, including a return for rework. The status is still %s — move it from the header.',
                $count === 1 ? 'Sign-off' : "{$count} sign-offs",
                $scenario->statusLabel()
            );
        }

        return sprintf(
            '%s recorded. The status is still %s — move it to Signed off from the header.',
            $count === 1 ? 'Sign-off' : "{$count} sign-offs",
            $scenario->statusLabel()
        );
    }
}
