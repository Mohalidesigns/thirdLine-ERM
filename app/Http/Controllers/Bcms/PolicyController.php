<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Services\Bcms\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The BC policy screen — clause 5.2 and its document control.
 *
 * The lifecycle rules — an approved version is immutable, an approver is not the
 * author, only an approved version can be attested — are all in
 * `PolicyService`, and this controller turns their exceptions into flash
 * messages. A user who clicks Approve on their own draft should be told why, not
 * shown a 403 that suggests their account is wrong.
 */
class PolicyController extends Controller
{
    public function __construct(private PolicyService $policies) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.plan.view');

        $history = $this->policies->history();

        return Inertia::render('Bcms/Policy/Index', [
            'current' => $this->policies->current()?->only(['id', 'uuid', 'title', 'version', 'status', 'content', 'effective_from', 'next_review_date']),
            'versions' => $history->map(fn (Plan $p): array => [
                'id' => $p->getKey(),
                'uuid' => $p->uuid,
                'title' => $p->title,
                'version' => $p->version,
                'status' => $p->status,
                'effective_from' => $p->effective_from?->toDateString(),
                'approved_at' => $p->approved_at?->toDateString(),
                'supersedes_plan_id' => $p->supersedes_plan_id,
                'immutable' => $p->isImmutable(),
                'content' => $p->content,
                'attestations' => $p->attestations->map(fn (PlanAttestation $a): array => [
                    'year' => $a->period_year,
                    'type' => $a->attestation_type,
                    'by' => $a->attested_by_name,
                    'role' => $a->attested_by_role,
                    'at' => $a->attested_at?->toDateTimeString(),
                    'statement' => $a->statement,
                ])->all(),
            ])->all(),
            'default_statement' => PolicyService::DEFAULT_STATEMENT,
            'can' => [
                'manage' => $request->user()?->can('bcms.plan.manage') === true,
                'approve' => $request->user()?->can('bcms.plan.approve') === true,
                'attest' => $request->user()?->can('bcms.programme.approve') === true,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'content' => ['nullable', 'array'],
            'next_review_date' => ['nullable', 'date', 'after:today'],
        ]);

        $policy = $this->policies->draft($data['title'], $data, $request->user()?->getKey());

        return back()->with('success', "Policy version {$policy->version} drafted.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        if ($plan->isImmutable()) {
            return back()->with('error',
                'An approved policy version cannot be edited. Supersede it with a new version — the version chain '
                .'is the history an auditor reads.');
        }

        $plan->update($request->validate([
            'title' => ['required', 'string', 'max:250'],
            'content' => ['nullable', 'array'],
            'next_review_date' => ['nullable', 'date'],
        ]) + ['updated_by' => $request->user()?->getKey()]);

        return back()->with('success', 'Policy draft saved.');
    }

    public function approve(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.approve');

        try {
            $this->policies->approve($plan, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Policy version {$plan->version} approved.");
    }

    public function supersede(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $data = $request->validate(['version' => ['required', 'string', 'max:20']]);

        try {
            $next = $this->policies->supersede($plan, $data['version'], $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success',
            "Version {$next->version} drafted. Version {$plan->version} is archived and stays retrievable.");
    }

    public function attest(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.programme.approve');

        $data = $request->validate([
            'period_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'attestation_type' => ['nullable', 'in:board,executive,owner'],
            'statement' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->policies->attest(
                $plan,
                $request->user(),
                $data['period_year'] ?? null,
                $data['attestation_type'] ?? 'board',
                $data['statement'] ?? null,
                $request->ip(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Attestation recorded.');
    }
}
