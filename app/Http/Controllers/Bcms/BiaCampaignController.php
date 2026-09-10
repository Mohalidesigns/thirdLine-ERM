<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\BiaCampaign;
use App\Models\Bcms\Process;
use App\Services\Bcms\Bia\BiaCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BIA campaigns — the distribution and chase dashboard (Blueprint §15, screen 2
 * of this phase).
 *
 * THE OVERDUE LIST IS THE SCREEN. A response funnel is a picture; the list of
 * named people who have not replied, with how many times they have been chased
 * and whether their manager has been told, is the thing a coordinator works
 * down on a Monday.
 */
class BiaCampaignController extends Controller
{
    public function __construct(private BiaCampaignService $campaigns) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.bia.view');

        $campaigns = BiaCampaign::query()->orderByDesc('id')->get();
        $selected = $request->integer('campaign') ?: $campaigns->first()?->getKey();
        $campaign = $selected === null ? null : $campaigns->firstWhere('id', $selected);

        return Inertia::render('Bcms/Bia/Campaigns', [
            'campaigns' => $campaigns->map(fn (BiaCampaign $c) => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'cycle' => $c->cycle,
                'status' => $c->status,
                'opens_at' => $c->opens_at?->toDateString(),
                'closes_at' => $c->closes_at?->toDateString(),
                // The rate is frozen at close; before that it is live.
                'response_rate' => $c->response_rate === null ? null : (float) $c->response_rate,
            ])->all(),
            'selected' => $campaign?->getKey(),
            'progress' => $campaign === null ? null : $this->campaigns->progress($campaign),
            'process_count' => Process::query()->where('status', 'active')->count(),
            'can' => ['manage' => $request->user()?->can('bcms.bia.campaign.manage') === true],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.bia.campaign.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'cycle' => ['required', 'in:annual,semi_annual,adhoc'],
            'opens_at' => ['nullable', 'date'],
            // A campaign with no deadline can never be overdue, so nothing can
            // ever be chased and criterion 7 is unreachable.
            'closes_at' => ['required', 'date', 'after:today'],
        ], [
            'closes_at.required' => 'A campaign needs a deadline. Without one nothing is ever overdue and nobody is ever chased.',
        ]);

        $campaign = $this->campaigns->create($data, $request->user()?->getKey());

        return redirect()->route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()])
            ->with('success', "Campaign '{$campaign->name}' created. Distribute it to create an assessment for every process in scope.");
    }

    public function distribute(Request $request, BiaCampaign $campaign): RedirectResponse
    {
        Gate::authorize('bcms.bia.campaign.manage');

        try {
            $result = $this->campaigns->distribute($campaign, null, $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = sprintf(
            '%d assessment(s) created%s.',
            $result['created'],
            $result['existing'] > 0 ? ", {$result['existing']} already existed" : ''
        );

        if ($result['without_owner'] !== []) {
            // Named, not swallowed. A campaign that reports full coverage
            // having never asked four owners is the failure this reports.
            $message .= sprintf(
                ' %d process(es) have no owner and nobody will be asked: %s.',
                count($result['without_owner']),
                implode(', ', array_slice(array_column($result['without_owner'], 'code'), 0, 8))
            );
        }

        return back()->with($result['without_owner'] === [] ? 'success' : 'error', $message);
    }

    public function chase(Request $request, BiaCampaign $campaign): RedirectResponse
    {
        Gate::authorize('bcms.bia.campaign.manage');

        $result = $this->campaigns->chase($campaign, $request->user()?->getKey());

        $message = sprintf('%d chased, %d escalated to a line manager.', $result['chased'], $result['escalated']);

        if ($result['no_manager'] !== []) {
            $message .= sprintf(
                ' %d could not be escalated because no manager is on record: %s.',
                count($result['no_manager']),
                implode(', ', array_slice($result['no_manager'], 0, 8))
            );
        }

        return back()->with('success', $message);
    }

    public function close(Request $request, BiaCampaign $campaign): RedirectResponse
    {
        Gate::authorize('bcms.bia.campaign.manage');

        $closed = $this->campaigns->close($campaign, $request->user()?->getKey());

        return back()->with('success', sprintf(
            'Campaign closed with a response rate of %s. That figure is now frozen — it is the clause 8.2.2 record '
            .'of what this campaign achieved.',
            $closed->response_rate === null ? 'no assessments' : $closed->response_rate.'%'
        ));
    }
}
