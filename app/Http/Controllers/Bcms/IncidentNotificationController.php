<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\ClassifyBcmsIncidentRequest;
use App\Http\Requests\Bcms\RecordBcmsIncidentNotificationRequest;
use App\Models\Bcms\Incident;
use App\Presenters\Bcms\IncidentPresenter;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Support\Bcms\CsvGuard;
use App\Support\Bcms\IncidentClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * `docs/bcms/screens/incident-notification-log.md` — a register, not a
 * composer. Nothing on this route transmits anything to a regulator
 * (ADR 0020 §2 point 4); this class only records that a submission happened.
 */
class IncidentNotificationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private IncidentService $incidents,
        private IncidentPresenter $presenter,
    ) {}

    public function index(Incident $incident): Response
    {
        Gate::authorize('bcms.incident.view');

        return Inertia::render('Bcms/Incidents/NotificationLog', $this->presenter->notificationLog($incident));
    }

    public function classify(ClassifyBcmsIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $data = $request->validated();

        if ($data['answer'] !== 'yes') {
            return back()->with('error', 'Classifying a new obligation from this screen only accepts "yes" — use the crisis room to record "no" or "unknown".');
        }

        $regulator = $data['question'] === 'personal_data' ? 'ndpc' : 'cbn';

        try {
            // Gate 1 re-gate defect 3: a closed/cancelled incident does not
            // gain a newly classified obligation from this screen either.
            $this->incidents->assertNotTerminal($incident);

            $this->notifications->classify(
                $incident,
                $request->user(),
                $regulator,
                isset($data['awareness_at']) ? IncidentClock::utc($data['awareness_at']) : null,
                $data['awareness_reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Obligation classified.');
    }

    public function store(RecordBcmsIncidentNotificationRequest $request, Incident $incident): RedirectResponse
    {
        try {
            $this->notifications->recordSubmission($incident, $request->user(), $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded as submitted.');
    }

    /**
     * Gated on the CONJUNCTION of `bcms.report.export` and `bcms.incident.view`
     * (Gate 2 review #1 defect 8) — this is regulatory-notification evidence
     * about a specific incident, and holding the general export permission
     * alone must not be enough to read it.
     */
    public function export(Incident $incident): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('bcms.report.export');
        Gate::authorize('bcms.incident.view');

        $rows = $incident->notifications()->with(['submittedBy:id,name', 'withdrawnBy:id,name', 'withdrawalEntry'])
            ->orderBy('awareness_at')->orderBy('sequence')->get();

        return response()->streamDownload(function () use ($rows, $incident) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Incident', 'Regulator', 'Basis clause', 'Kind', 'Sequence', 'Awareness at', 'Due at',
                'Submitted at', 'Submitted by', 'Reference',
                // Amendment 2's evidence-pack requirement: a withdrawn
                // obligation is shown in full, never filtered out, plus
                // whether the withdrawal came before or after `due_at` — a
                // comparison of two stored timestamps, printed as a sentence
                // rather than left for the reader to work out.
                'Withdrawn at', 'Withdrawn by', 'Withdrawal rationale', 'Withdrawal timing',
            ]);

            foreach ($rows as $row) {
                $withdrawalTiming = $row->withdrawn_at === null
                    ? null
                    : ($row->due_at !== null && $row->withdrawn_at->gt($row->due_at)
                        ? 'Withdrawn after the reporting deadline had passed.'
                        : 'Withdrawn before the reporting deadline.');

                // Advisory A7: every user-supplied string (a reference, a
                // name, the decision log's own free text) is CSV
                // formula-injection guarded before it reaches the file —
                // this is regulatory evidence, the file most likely to be
                // opened by someone the incident was about.
                fputcsv($out, CsvGuard::row([
                    $incident->reference,
                    $row->regulator->label(),
                    $row->basis_clause_ref,
                    $row->kind->label(),
                    $row->sequence,
                    $row->awareness_at?->toIso8601String(),
                    $row->due_at?->toIso8601String(),
                    $row->submitted_at?->toIso8601String(),
                    $row->submittedBy?->name,
                    $row->reference,
                    $row->withdrawn_at?->toIso8601String(),
                    $row->withdrawnBy?->name,
                    // The decision-log entry's own content already carries
                    // "options considered" and "rationale" (`IncidentService::log()`),
                    // and is the withdrawal's one recorded reason.
                    $row->withdrawalEntry?->content,
                    $withdrawalTiming,
                ]));
            }

            fclose($out);
        }, "{$incident->reference}-notifications.csv");
    }
}
