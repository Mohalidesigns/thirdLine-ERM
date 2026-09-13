<?php

namespace App\Http\Controllers\Tprm;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Presenters\GridPresenter;
use App\Services\Llm\UsageReporter;
use App\Services\Tprm\Ai\TprmAiPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The usage report — phase-11a-ai-contract.md §7.3, `docs/tprm/screens/ai-usage.md`.
 *
 * NO POLICY, SAME GATE AS `AiSettingsController` — ADR 0015 §8.
 *
 * EVERY AGGREGATE HERE IS A PLAIN `GROUP BY` THROUGH `UsageReporter`, which is
 * the class that actually owns the portable-SQL requirement (contract §8.12).
 * This controller's only job is to select the month, compose the panels, and
 * refuse to print a number nobody measured.
 */
class AiUsageController extends Controller
{
    public function __construct(
        private readonly UsageReporter $usage,
        private readonly TprmAiPolicy $policy,
    ) {}

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('tprm.admin');

        $organizationId = (int) TenantContext::organizationId();
        $settings = $this->policy->settingsFor($organizationId);

        $retainedMonths = $this->usage->retainedMonths();
        $requestedMonth = (string) $request->query('month', '');
        $inRange = in_array($requestedMonth, $retainedMonths, true);
        $usageMonth = $inRange ? $requestedMonth : $this->usage->currentMonth();

        // Normalise the query the grid reads too — `TprmAiUsageEventsGrid`
        // reads `month` from the request independently (GridRegistry::resolve()
        // takes no constructor argument), so an invalid or out-of-range value
        // in the URL must be corrected here, once, rather than the grid and
        // the panels above silently disagreeing about which month is showing.
        $request->query->set('month', $usageMonth);

        $summary = $this->usage->monthSummary($organizationId, $usageMonth);

        $tokenCap = $settings->ai_monthly_token_cap;
        $callCap = $settings->ai_monthly_call_cap;

        return Inertia::render('Tprm/Settings/AiUsage', [
            'months' => $retainedMonths,
            // The substitution above is right — see the comment on it — but
            // must be DECLARED, not silent (ruled 2026-09-11, frontend
            // deviation 3; ADR 0015 §6b's truncation discipline applied to a
            // month). Null when nothing was asked for, or when what was asked
            // for was already valid and no substitution happened at all;
            // otherwise the exact out-of-range string the URL requested, so
            // the screen can say which month it was asked for and why that
            // month is not what is showing.
            'requested_month' => ($requestedMonth === '' || $inRange) ? null : $requestedMonth,
            'month' => [
                'usage_month' => $usageMonth,
                'total_tokens' => $summary['total_tokens'],
                'call_count' => $summary['call_count'],
                'token_cap' => $tokenCap,
                'call_cap' => $callCap,
                // Already in the frozen §7.1 field list and simply was not
                // being sent (ruled 2026-09-11, frontend deviation 2) — one
                // home for the subtraction, shared with AiSettingsController.
                'remaining' => $this->usage->remaining($tokenCap, $callCap, $summary['total_tokens'], $summary['call_count']),
                // Tone is a judgement (a threshold), not arithmetic, and
                // belongs server-side for the same reason `remaining` does —
                // a browser-side copy of the 90% threshold is a second home
                // for it that will disagree the day either one is tuned.
                'token_cap_tone' => $this->usage->tone($tokenCap, $summary['total_tokens']),
                'call_cap_tone' => $this->usage->tone($callCap, $summary['call_count']),
            ],
            'byService' => $this->usage->byService($organizationId, $usageMonth)->values(),
            'byOutcome' => $this->usage->byOutcome($organizationId, $usageMonth)->values(),
            'grid' => fn () => $presenter->present(
                GridRegistry::resolve('tprm_ai_usage_events'),
                $request,
                $request->user()
            ),
            'retentionMonths' => (int) config('llm.retention_months'),
            // Spec addition, docs/tprm/screens/ai-usage.md §3 / §7 — not in
            // contract §7.3's field list. Tells an empty month with AI
            // currently OFF (expected, benign) apart from an empty month with
            // AI currently ON (worth checking the breaker/endpoint for). Cheap:
            // it is the same resolution `AiSettingsController` already
            // computes for `effective.enabled`, read here through the same
            // `TprmAiPolicy` rather than re-derived.
            'tenant_ai_currently_enabled' => $this->policy->masterEnabled($organizationId),
        ]);
    }
}
