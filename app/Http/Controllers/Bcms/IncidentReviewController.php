<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\FinaliseBcmsPirRequest;
use App\Models\Bcms\Incident;
use App\Presenters\Bcms\IncidentPresenter;
use App\Services\Bcms\Incidents\PirService;
use App\Support\Bcms\CsvGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The post-incident review — an `Aar` row with `incident_id` set, not a new
 * table (`docs/bcms/screens/pir-post-incident-review.md`, ADR 0020 §1).
 *
 * `show()` RENDERS A STANDALONE SCREEN, NOT `bcms.aars.show`. The spec's own
 * preference (§1) is the exact same route the exercise AAR uses,
 * disambiguated server-side — but `AarController`/`Aar.jsx` sit outside this
 * session's edit boundary (Phase 9's files), `AarController::show()` gates on
 * `bcms.exercise.view` only (wrong for a PIR per the spec's own permission
 * note), and `Aar.jsx` renders every field against an assumed `occurrence`
 * that is null for a PIR. Rather than build against a screen this session
 * cannot correct, `Bcms/Incidents/Review` is a purpose-built page reusing
 * `bcms.aars.update`/`.reopen`/`.distribute`/`.ai-draft` unmodified for
 * writes (`AarService::update()` is already subject-agnostic) and gated
 * correctly on `bcms.incident.view`/`.manage` for reads/writes that are
 * incident-shaped. Flagged in this phase's handoff as a deliberate deviation
 * from the spec's "same URL" instruction, with the reason recorded here.
 */
class IncidentReviewController extends Controller
{
    public function __construct(
        private PirService $pir,
        private IncidentPresenter $presenter,
    ) {}

    public function start(Incident $incident): RedirectResponse
    {
        Gate::authorize('bcms.incident.manage');

        if ($incident->status?->value !== 'closed') {
            return back()->with('error', 'A post-incident review can only be started once the incident is closed.');
        }

        $aar = $this->pir->ensureDraftFor($incident);
        // Persisted once, here, as the one EXPLICIT write action that opens
        // the draft (advisory A9) — every later GET recomputes without
        // writing. Gap 3: condition 7's timings are computed the same way.
        $this->pir->refreshPlanSections($aar, persist: true);
        $this->pir->refreshMetrics($aar, persist: true);

        return redirect()->route('bcms.incidents.review.show', $incident);
    }

    public function show(Incident $incident): Response|RedirectResponse
    {
        Gate::authorize('bcms.incident.view');

        $aar = $incident->reviews()->first();

        if ($aar === null) {
            return redirect()->route('bcms.incidents.crisis-room', $incident)
                ->with('error', 'No post-incident review exists for this incident yet. Start one from the crisis room once it is closed.');
        }

        // Advisory A9: computed fresh on every read and merged onto `$aar`
        // IN MEMORY ONLY (`refreshPlanSections()`'s default `$persist =
        // false`) — nothing is written by this GET. `$aar` (not `$aar->
        // fresh()`, which would discard the in-memory merge by re-querying)
        // is what the presenter reads. Gap 3: same split for the timings.
        $this->pir->refreshPlanSections($aar);
        $this->pir->refreshMetrics($aar);

        return Inertia::render('Bcms/Incidents/Review', $this->presenter->review($incident, $aar));
    }

    /**
     * `bcms.incident.manage` + `bcms.aar.approve` both required (defect 5,
     * enforced again here on top of the route's own conjunction middleware)
     * — `pir-post-incident-review.md` §1's permission note. The realised-loss
     * confirmation (defect 7) is this action's one accepted field;
     * `FinaliseBcmsPirRequest` shapes it.
     */
    public function finalise(FinaliseBcmsPirRequest $request, Incident $incident): RedirectResponse
    {
        $aar = $incident->reviews()->first();

        if ($aar === null) {
            return back()->with('error', 'No post-incident review exists for this incident yet.');
        }

        try {
            $this->pir->finalise($aar, $request->user(), (int) $request->validated('realised_loss_minor'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('bcms.incidents.review.show', $incident)
            ->with('success', 'Post-incident review finalised.');

        // A3 (code review #3 advisory): the review write itself never fails
        // because the ERM side could not be reached (`ErmBridge` is
        // tolerant of a missing target by design) — but a silent failure
        // there leaves an officer believing a confirmed loss reached the
        // register when it did not. Surfaced, not hidden; still logged by
        // `ErmBridge` itself either way.
        if ($this->pir->mirrorFailed()) {
            $redirect->with(
                'warning',
                'The review was finalised, but the confirmed realised loss could not be mirrored into the '
                .'ERM loss-event register. This has been logged — the loss register may need a manual entry.'
            );
        }

        return $redirect;
    }

    /**
     * Gated on the CONJUNCTION of `bcms.report.export` and `bcms.incident.view`
     * (Gate 2 review #1 defect 8) — a PIR can carry personal data about staff
     * and customers, and the general export permission alone must not be
     * enough to read it.
     */
    public function export(Incident $incident): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('bcms.report.export');
        Gate::authorize('bcms.incident.view');

        $aar = $incident->reviews()->first();

        // Gate 1 re-gate defect 8: eager-load `loggedBy` once rather than
        // once per entry in the loop below.
        $entries = $incident->entries()->with('loggedBy:id,name')->orderBy('logged_at')->get();

        return response()->streamDownload(function () use ($aar, $incident, $entries) {
            $out = fopen('php://output', 'w');
            // Advisory A7: this is regulatory/incident evidence, the file
            // most likely to be opened by someone the incident was about.
            fputcsv($out, CsvGuard::row(['Post-incident review', $incident->reference, $incident->title]));
            fputcsv($out, CsvGuard::row(['Status', $aar?->status]));
            fputcsv($out, CsvGuard::row(['Clause ref', $aar?->iso_clause_ref]));
            fputcsv($out, CsvGuard::row(['Summary', $aar?->summary]));
            fputcsv($out, CsvGuard::row(['What worked', $aar?->what_worked]));
            fputcsv($out, CsvGuard::row(['What did not work', $aar?->what_failed]));
            fputcsv($out, []);
            fputcsv($out, ['Decision log']);
            fputcsv($out, ['Logged at', 'Type', 'Content', 'By', 'Supersedes']);

            foreach ($entries as $entry) {
                fputcsv($out, CsvGuard::row([
                    $entry->logged_at?->toIso8601String(),
                    $entry->entry_type->value,
                    $entry->content,
                    $entry->loggedBy?->name,
                    $entry->supersedes_entry_id,
                ]));
            }

            fclose($out);
        }, "{$incident->reference}-post-incident-review.csv");
    }
}
