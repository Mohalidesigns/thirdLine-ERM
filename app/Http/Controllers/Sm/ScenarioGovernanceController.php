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
use Illuminate\Support\Str;

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
     *
     * C2.2's STATUS GUARD is checked first, so a frozen record can gain neither a row nor a
     * review date — the date write is part of the same act, and one landing after the freeze
     * breaks spec 01 §5.5's "the figures it carries are the ones recorded at sign-off" from
     * the side nobody watches.
     */
    public function storeSignoffs(StoreScenarioSignoffsRequest $request, Scenario $scenario): RedirectResponse
    {
        // A lifecycle rule, so a flash rather than a 403 (standard §3) — the same shape as
        // storeNotes' archived refusal below. THE MESSAGE LIVES HERE AND NOT ONLY IN THE
        // SERVICE (C2.2, "both, not either"): a `ScenarioRefused` thrown inside
        // `recordSignoffs`' transaction rolls back correctly and then surfaces as whatever the
        // exception handler decides, which is not a sentence a signer can act on. The service
        // keeps its own copy of the check as the backstop a Phase 5 import or a direct call
        // meets.
        if (($refusal = $this->signoffStatusRefusal($scenario)) !== null) {
            return back()->with('error', $refusal);
        }

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
     * C2.2's status guard on recording sign-offs: the sentence to flash, or null to proceed.
     *
     * TWO CONDITIONS, AND BOTH ARE NEEDED. The record is frozen (`Scenario::isFrozen()`, over
     * `FROZEN_STATUSES = ['signed_off', 'archived']`), or `signed_off` is no longer reachable
     * from where it stands. Reachability is COMPUTED from `Scenario::STATUS_TRANSITIONS` by
     * `Scenario::canReach()` and never written as a status list: a hardcoded "these six can
     * still be signed off" is a second copy of the map, and the two diverge the first time an
     * edge changes.
     *
     * NEITHER CLAUSE SUBSUMES THE OTHER. `signed_off` REACHES `signed_off` — reopen for
     * revalidation, then the whole design path back — so reachability alone would let a frozen
     * record take a new row. And on the shipped map the reachability clause fires only for
     * `archived` (Addendum-4: terminal in v1), which `isFrozen()` already covers, so it earns
     * its place the day an edge is removed rather than today. `draft`, `in_design`, `designed`,
     * `in_validation`, `validated` and `returned_for_rework` all still reach `signed_off` and
     * all still accept sign-offs.
     *
     * DELIBERATELY NOT `ScenarioService::refuseIfReadOnly()`, and the distinction is the whole
     * content of C2.2: that helper treats `validated` as read-only and answers "Return it for
     * rework to change it", so reusing it verbatim would refuse the LEGITIMATE
     * `validated → signed_off` path and break sign-off outright. Sign-off is the one write a
     * read-only status permits, by design.
     */
    private function signoffStatusRefusal(Scenario $scenario): ?string
    {
        if ($scenario->status === 'archived') {
            return 'This scenario is archived. Sign-offs can no longer be recorded against it.';
        }

        if ($scenario->isFrozen()) {
            return 'This scenario is signed off and frozen. Reopen it for revalidation to record a new sign-off.';
        }

        if (! $scenario->canReach('signed_off')) {
            return sprintf(
                'Sign-offs are recorded on a scenario that can still be signed off. This one is %s.',
                Str::lower($scenario->statusLabel())
            );
        }

        return null;
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
