<?php

namespace App\Http\Controllers\Risk;

use App\Events\RcsaWorksheetSubmitted;
use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\AddAssignmentRequest;
use App\Http\Requests\Campaigns\ReviewAssignmentRequest;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\SubmitResponseRequest;
use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\Control;
use App\Models\Questionnaire;
use App\Models\Risk;
use App\Presenters\GridPresenter;
use App\Services\Campaigns\CampaignDashboardService;
use App\Services\NotificationService;
use App\Services\ReferenceCodeService;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CampaignController extends Controller
{
    public function dashboard(CampaignDashboardService $dashboard)
    {
        Gate::authorize('viewAny', AssessmentCampaign::class);

        // The four aggregates used to be four inline queries here; they are one
        // grouped query plus a count in the service, pinned by
        // Characterisation\CampaignDashboardFiguresTest.
        $stats = $dashboard->stats();
        $campaigns = $dashboard->recent();

        return view('risk.campaigns.dashboard', [...$stats, 'campaigns' => $campaigns]);
    }

    /**
     * WP-09: the register is the shared data grid — see
     * App\Grids\Definitions\CampaignsGrid, which also renders the status and
     * type filters this method used to read from the query string without the
     * view ever offering a control for them.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', AssessmentCampaign::class);

        $total = AssessmentCampaign::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('Campaigns/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('campaigns'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', AssessmentCampaign::class);

        return view('risk.campaigns.create', $this->campaignFormOptions());
    }

    public function store(StoreCampaignRequest $request)
    {
        $campaign = AssessmentCampaign::create([
            ...$request->safe()->only([
                'title', 'description', 'campaign_type', 'questionnaire_id',
                'start_date', 'end_date', 'reviewer_id',
            ]),
            'organization_id' => auth()->user()->organization_id,
            'campaign_code' => ReferenceCodeService::generate('assessment_campaigns', 'campaign_code', 'CAM'),
            'created_by' => auth()->id(),
            'status' => 'draft',
        ]);

        return redirect()->route('risk.campaigns.show', $campaign)->with('success', 'Campaign created. Add assignments to proceed.');
    }

    public function show(AssessmentCampaign $campaign)
    {
        Gate::authorize('view', $campaign);

        $campaign->load(['assignments.businessUnit', 'assignments.respondent', 'assignments.reviewer', 'questionnaire', 'creator']);

        // Drives the "View submission" link: an assignment with lines against
        // it has something to show, whatever its status.
        $campaign->assignments->loadCount('responses');

        return view('risk.campaigns.show', compact('campaign'));
    }

    public function addAssignment(AddAssignmentRequest $request, AssessmentCampaign $campaign)
    {
        $assignment = CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $request->business_unit_id,
            'respondent_id' => $request->respondent_id,
            'reviewer_id' => $request->reviewer_id ?? $campaign->reviewer_id,
            'due_date' => $request->due_date,
            'status' => 'pending',
        ]);

        $campaign->recalculateProgress();

        // Somebody has just been given work with a deadline on it. Being told
        // is the whole point of the assignment; before this, the only way a
        // respondent learned they were on an RCSA was for someone to email
        // them out of band.
        NotificationService::send(
            (int) $campaign->organization_id,
            (int) $assignment->respondent_id,
            'campaign_assignment',
            "RCSA Assignment: {$campaign->campaign_code}",
            "You have been assigned to '{$campaign->title}'".
                ($assignment->due_date ? ', due '.$this->dateFor($assignment->due_date).'.' : '.'),
            ['entity_type' => 'campaign_assignment', 'entity_id' => $assignment->id, 'campaign_id' => $campaign->id],
            $this->respondUrl($assignment),
            'medium',
            'assessment',
        );

        return back()->with('success', 'Assignment added.');
    }

    public function launch(AssessmentCampaign $campaign)
    {
        Gate::authorize('manage', $campaign);

        if ($campaign->assignments()->count() === 0) {
            return back()->with('error', 'Cannot launch a campaign with no assignments.');
        }

        $campaign->update([
            'status' => 'active',
            'launched_at' => now(),
        ]);

        // The single largest gap in the module: launching a campaign to two
        // hundred respondents told none of them. The recipients were always
        // right there in campaign_assignments.
        //
        // Only the outstanding ones. An assignment that is already submitted
        // or approved — which happens whenever a worksheet was filed against a
        // draft campaign, or the campaign is being re-launched after a pause —
        // is finished work, and telling that person to go and do it is exactly
        // the kind of notification that teaches people to ignore the bell.
        $respondents = $campaign->assignments()
            ->whereNotIn('status', ['submitted', 'approved'])
            ->pluck('respondent_id')
            ->all();

        // sendMany() sends a single recipient inline and hands anything larger
        // to FanOutNotificationsJob, so a 200-respondent bank launch is one
        // queued job rather than 200 inserts inside this request.
        NotificationService::sendMany(
            (int) $campaign->organization_id,
            array_map('intval', array_filter($respondents)),
            'campaign_launched',
            "RCSA Campaign Open: {$campaign->campaign_code}",
            "'{$campaign->title}' is now open for response".
                ($campaign->end_date ? '. Responses are due by '.$this->dateFor($campaign->end_date).'.' : '.'),
            ['entity_type' => 'campaign', 'entity_id' => $campaign->id],
            "/risk/campaigns/{$campaign->id}",
            'high',
            'assessment',
        );

        return back()->with('success', 'Campaign launched successfully.');
    }

    /**
     * Read-back of what a respondent actually submitted.
     *
     * The respond screen is a blank entry form built from the business unit's
     * register risks, so it shows nothing of a submission whose lines are
     * free-text — which is every RCSA worksheet line, since those carry
     * risk_id = null and keep their content in questionnaire_data. Until this
     * screen existed a submitted worksheet was stored and auditable but had
     * nowhere in the interface that displayed it back.
     */
    public function submission(CampaignAssignment $assignment)
    {
        $campaign = $this->tenantCampaignFor($assignment);

        Gate::authorize('view', $campaign);

        $assignment->load(['businessUnit', 'respondent', 'reviewer', 'responses.risk', 'responses.control']);
        $assignment->setRelation('campaign', $campaign);

        return view('risk.campaigns.submission', compact('assignment', 'campaign'));
    }

    public function respond(CampaignAssignment $assignment)
    {
        $campaign = $this->tenantCampaignFor($assignment);

        Gate::authorize('respond', $campaign);

        $assignment->load(['campaign.questionnaire.sections.questions', 'businessUnit', 'responses']);

        $orgId = auth()->user()->organization_id;
        $risks = Risk::where('organization_id', $orgId)->where('business_unit_id', $assignment->business_unit_id)->get();
        $controls = Control::where('organization_id', $orgId)->where('business_unit_id', $assignment->business_unit_id)->get();

        if ($assignment->status === 'pending') {
            $assignment->update(['status' => 'in_progress', 'started_at' => now()]);
        }

        return view('risk.campaigns.respond', compact('assignment', 'risks', 'controls'));
    }

    public function submitResponse(SubmitResponseRequest $request, CampaignAssignment $assignment)
    {
        $campaign = $this->tenantCampaignFor($assignment);

        $responses = $request->validated('responses');

        // Clear existing responses
        $assignment->responses()->delete();

        foreach ($responses as $responseData) {
            $likelihood = $responseData['likelihood_score'] ?? null;
            $impact = $responseData['impact_score'] ?? null;
            $score = ($likelihood && $impact) ? $likelihood * $impact : null;

            CampaignResponse::create([
                'assignment_id' => $assignment->id,
                'risk_id' => $responseData['risk_id'] ?? null,
                'control_id' => $responseData['control_id'] ?? null,
                'likelihood_score' => $likelihood,
                'impact_score' => $impact,
                'overall_score' => $score,
                'rating' => $score ? $this->scoreToRating($score) : null,
                'control_effectiveness' => $responseData['control_effectiveness'] ?? null,
                'comments' => $responseData['comments'] ?? null,
                'questionnaire_data' => $responseData['questionnaire_data'] ?? null,
            ]);
        }

        $assignment->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $assignment->campaign->recalculateProgress();

        // This is the second route into a submitted worksheet — the first is
        // RcsaController::storeWorksheet(), which has dispatched this event
        // since it was written. A submission made from the campaign screen
        // instead of the RCSA worksheet screen produced the same row in the
        // same table and put nothing in the reviewer's queue, so which screen
        // the respondent happened to use decided whether their work was ever
        // looked at. Same event, same listener, same reviewer.
        RcsaWorksheetSubmitted::dispatch(
            $assignment->fresh(),
            $campaign,
            count($responses),
        );

        return redirect()->route('risk.campaigns.show', $assignment->campaign)->with('success', 'Assessment submitted for review.');
    }

    public function reviewAssignment(ReviewAssignmentRequest $request, CampaignAssignment $assignment)
    {
        $campaign = $this->tenantCampaignFor($assignment);

        $approved = $request->validated('action') === 'approve';

        $assignment->update([
            'status' => $approved ? 'approved' : 'rejected',
            'reviewed_at' => now(),
            'reviewer_id' => auth()->id(),
            'reviewer_notes' => $request->reviewer_notes,
        ]);

        $assignment->campaign->recalculateProgress();

        // A rejection is a request for rework and the respondent is the only
        // person who can do it, so it is high priority and points at the form
        // rather than the read-back. An approval closes the loop: lower
        // priority, but still told — silence after submitting is why people
        // chase reviewers by email.
        $notes = trim((string) $request->reviewer_notes);

        NotificationService::send(
            (int) $campaign->organization_id,
            (int) $assignment->respondent_id,
            $approved ? 'campaign_assignment_approved' : 'campaign_assignment_rejected',
            ($approved ? 'RCSA Assessment Approved: ' : 'RCSA Assessment Returned: ').$campaign->campaign_code,
            "Your submission for '{$campaign->title}' has been ".($approved ? 'approved' : 'returned for rework').'.'.
                ($notes === '' ? '' : " Reviewer notes: {$notes}"),
            [
                'entity_type' => 'campaign_assignment',
                'entity_id' => $assignment->id,
                'campaign_id' => $campaign->id,
                'outcome' => $approved ? 'approved' : 'rejected',
            ],
            $approved ? $this->submissionUrl($assignment) : $this->respondUrl($assignment),
            $approved ? 'medium' : 'high',
            'assessment',
        );

        return back()->with('success', 'Assignment '.$request->action.'d.');
    }

    public function closeCampaign(AssessmentCampaign $campaign)
    {
        Gate::authorize('manage', $campaign);

        $campaign->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return back()->with('success', 'Campaign closed.');
    }

    /**
     * The three pick-lists the create screen needs, all tenant-bound.
     *
     * Only PUBLISHED questionnaires: an unpublished one can still have its
     * questions rewritten, and StoreCampaignRequest refuses it — the list and
     * the validator now agree.
     *
     * `businessUnits` and `users` are here rather than queried from the view.
     * The campaign show screen used to build both lists inside the Blade
     * template itself, which is a query the controller cannot see, cannot
     * scope and cannot test.
     *
     * @return array<string, \Illuminate\Support\Collection<int, mixed>>
     */
    private function campaignFormOptions(): array
    {
        $orgId = auth()->user()->organization_id;

        return [
            'questionnaires' => Questionnaire::where('organization_id', $orgId)
                ->where('status', 'published')
                ->orderBy('title')
                ->get(['id', 'title']),
            'businessUnits' => BusinessUnit::where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'users' => \App\Models\User::where('organization_id', $orgId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * The assignment's campaign, or 404.
     *
     * campaign_assignments carries no organization_id of its own, so route
     * model binding on {assignment} resolves any id in the table regardless of
     * tenant. AssessmentCampaign does carry the OrganizationScope, so a
     * foreign campaign reads back as null through the relation — which makes
     * this both the tenancy check and the lookup.
     */
    private function tenantCampaignFor(CampaignAssignment $assignment): AssessmentCampaign
    {
        $campaign = $assignment->campaign()->first();

        abort_if($campaign === null, 404);

        return $campaign;
    }

    /**
     * Where a respondent goes to do the work.
     *
     * Root-relative, and built by name rather than by string so a change to
     * the route prefix cannot leave months of stored notifications pointing at
     * a 404 — NotificationService::normaliseActionUrl() explains why a stored
     * action URL must never carry a host.
     */
    private function respondUrl(CampaignAssignment $assignment): string
    {
        return route('risk.campaigns.respond', $assignment, false);
    }

    /** Where a respondent goes to read back what they filed. */
    private function submissionUrl(CampaignAssignment $assignment): string
    {
        return route('risk.campaigns.submission', $assignment, false);
    }

    /**
     * A date for a notification body, whatever the column casts to.
     *
     * due_date and end_date are cast on some models and plain strings on
     * others; a notification is not worth a TypeError either way.
     */
    private function dateFor(mixed $date): string
    {
        try {
            return \Illuminate\Support\Carbon::parse((string) $date)->toFormattedDateString();
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    /**
     * Delegates to RiskScoringService — there is one set of rating bands.
     *
     * This used to be a private copy using >= 6 for Medium while the service
     * used >= 5, so a risk scoring exactly 5 was Medium or Low depending on
     * which screen you were looking at. The service's bands win.
     */
    private function scoreToRating(int $score): string
    {
        return app(RiskScoringService::class)->calculateRating($score);
    }
}
