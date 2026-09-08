<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Models\Tprm\Incident;
use App\Models\Tprm\IncidentEscalation;
use App\Models\Tprm\NotificationDraft;
use App\Models\Tprm\TprmSetting;
use App\Services\Tprm\Incidents\ClockAssessment;
use App\Services\Tprm\Incidents\IncidentErmBridge;
use App\Services\Tprm\Incidents\NotificationDraftService;
use App\Services\Tprm\Incidents\ObligationClockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

/**
 * Third-party incidents and their regulatory clocks — AC-07.
 *
 * THE SCREEN LEADS WITH THE COUNTDOWN, and the countdown is the only thing
 * above the fold. Everything else about an incident can wait; a 24-hour window
 * cannot, and a page that put the description first would bury the one number
 * that changes what somebody does in the next hour.
 *
 * AN UNDETERMINED ASSESSMENT IS SHOWN AS A QUESTION, NOT AS A GREEN TICK. Where
 * the CBN materiality test cannot be run — because shareholders' funds have not
 * been recorded — the screen says so and says what to do. That state exists
 * precisely so it can appear here rather than being silently resolved to "not
 * reportable".
 */
class IncidentController extends Controller
{
    public function __construct(
        private readonly ObligationClockService $clocks,
        private readonly NotificationDraftService $drafts,
        private readonly IncidentErmBridge $erm,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.incident.view');

        $incidents = Incident::query()
            ->with('thirdParty:id,uuid,legal_name')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tprm/Incidents/Index', [
            'incidents' => $incidents->map(fn (Incident $incident): array => [
                'uuid' => $incident->uuid,
                'reference' => $incident->reference,
                'title' => $incident->title,
                'third_party' => $incident->thirdParty?->legal_name,
                'type' => $incident->type,
                'status' => $incident->status,
                'reported_to_us_at' => $incident->reported_to_us_at?->toDayDateTimeString(),
                'reported_by' => $incident->reported_by,
                'clocks' => $this->clocks->countdowns($incident),
            ])->values(),
            'settings' => $this->settingsBanner(),
            'can' => [
                'manage' => $request->user()->can('tprm.incident.manage'),
                'notify' => $request->user()->can('tprm.incident.notify'),
            ],
        ]);
    }

    public function show(Request $request, Incident $incident)
    {
        Gate::authorize('tprm.incident.view');

        $incident->load('thirdParty:id,uuid,legal_name');

        return Inertia::render('Tprm/Incidents/Show', [
            'incident' => [
                'uuid' => $incident->uuid,
                'reference' => $incident->reference,
                'title' => $incident->title,
                'description' => $incident->description,
                'type' => $incident->type,
                'status' => $incident->status,
                'third_party' => $incident->thirdParty?->legal_name,
                'detected_at' => $incident->detected_at?->toDayDateTimeString(),
                'reported_to_us_at' => $incident->reported_to_us_at?->toDayDateTimeString(),
                'reported_by' => $incident->reported_by,
                'personal_data_involved' => $incident->personal_data_involved,
                'customer_impact' => $incident->customer_impact,
                'data_subjects_affected' => $incident->data_subjects_affected,
                'customers_affected' => $incident->customers_affected,
                'estimated_loss_minor' => $incident->estimated_loss_minor,
                'currency' => $incident->currency,
                'root_cause' => $incident->root_cause,
                'erm_loss_event_id' => $incident->erm_loss_event_id,
            ],
            'clocks' => $this->clocks->countdowns($incident),
            'assessments' => $this->assessmentPanel($incident),
            'drafts' => $this->draftPanel($incident),
            'escalations' => IncidentEscalation::query()
                ->where('incident_id', $incident->getKey())
                ->orderBy('fired_at')
                ->get()
                ->map(fn (IncidentEscalation $e): array => [
                    'regulator' => $e->regulator->shortLabel(),
                    'threshold_pct' => $e->threshold_pct,
                    'fired_at' => $e->fired_at?->toDayDateTimeString(),
                    'recipients' => count((array) $e->notified_user_ids),
                ])->values(),
            'settings' => $this->settingsBanner(),
            'can' => [
                'manage' => $request->user()->can('tprm.incident.manage'),
                'notify' => $request->user()->can('tprm.incident.notify'),
            ],
        ]);
    }

    /** Re-run both assessments after the facts have been corrected. */
    public function assess(Request $request, Incident $incident)
    {
        Gate::authorize('tprm.incident.manage');

        $this->clocks->assess($incident);

        return back()->with('success', 'Reportability reassessed.');
    }

    public function buildDrafts(Request $request, Incident $incident)
    {
        Gate::authorize('tprm.incident.manage');

        try {
            $drafts = $this->drafts->buildDue($incident, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $drafts === []
            ? 'Neither clock is running, so there is nothing to draft.'
            : sprintf('%d draft(s) prepared for review.', count($drafts)));
    }

    public function approveDraft(Request $request, NotificationDraft $draft)
    {
        Gate::authorize('tprm.incident.notify');

        try {
            $this->drafts->approve($draft, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Approved. Send it, then record the submission here.');
    }

    /**
     * Record that a named officer sent it — the only thing that stops a clock.
     */
    public function recordSubmission(Request $request, NotificationDraft $draft)
    {
        Gate::authorize('tprm.incident.notify');

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'submitted_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        try {
            $this->drafts->recordSubmission(
                $draft,
                $request->user(),
                $validated['reference'],
                isset($validated['submitted_at'])
                    ? \Illuminate\Support\Carbon::parse($validated['submitted_at'])
                    : null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Recorded. That clock has stopped.');
    }

    public function postToLossRegister(Request $request, Incident $incident)
    {
        Gate::authorize('tprm.incident.manage');

        $result = $this->erm->mirror($incident);

        if ($result['loss_event'] === null) {
            return back()->with('error', $result['reason']);
        }

        $this->erm->applyScoreUplift($incident);

        return back()->with('success', sprintf(
            'Posted to the loss register as %s.',
            $result['loss_event']->event_reference,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assessmentPanel(Incident $incident): array
    {
        return array_map(
            fn (ClockAssessment $assessment): array => $assessment->toArray() + [
                'regulator_label' => $assessment->regulator->shortLabel(),
                'citation' => $assessment->regulator->citation(),
            ],
            [
                $this->clocks->assessNdpc($incident),
                $this->clocks->assessCbn($incident),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftPanel(Incident $incident): array
    {
        return NotificationDraft::query()
            ->where('incident_id', $incident->getKey())
            ->get()
            ->map(fn (NotificationDraft $draft): array => [
                'id' => $draft->getKey(),
                'regulator' => $draft->regulator->shortLabel(),
                'title' => $draft->title,
                'body' => $draft->body,
                'gaps' => $draft->gaps ?? [],
                'status' => $draft->status,
                'approved_at' => $draft->approved_at?->toDayDateTimeString(),
                'submitted_at' => $draft->submitted_at?->toDayDateTimeString(),
                'submission_reference' => $draft->submission_reference,
                'can_submit' => $draft->canBeSubmitted(),
            ])->values()->all();
    }

    /**
     * Whether the CBN materiality test can be run at all.
     *
     * SHOWN ON EVERY INCIDENT SCREEN, not buried in settings. A tenant that
     * has not recorded its shareholders' funds cannot have the 0.01% test run
     * — and the place that matters is the screen where somebody is deciding
     * whether a twenty-four-hour clock has started.
     *
     * @return array<string, mixed>
     */
    private function settingsBanner(): array
    {
        $setting = TprmSetting::forOrganization((int) request()->user()->organization_id);

        return [
            'has_materiality_basis' => $setting->hasMaterialityBasis(),
            'shareholders_funds_as_at' => $setting->shareholders_funds_as_at?->toDateString(),
            'age_months' => $setting->ageInMonths(),
            'threshold_minor' => $setting->cbnMaterialityThresholdMinor(),
            'note' => $setting->hasMaterialityBasis()
                ? null
                : 'Shareholders\' funds have not been recorded, so the CBN 0.01% materiality test cannot be '
                    .'run. Incidents whose only trigger would be financial loss will be reported as '
                    .'undetermined — decide those by hand.',
        ];
    }
}
