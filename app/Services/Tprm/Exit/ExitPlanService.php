<?php

namespace App\Services\Tprm\Exit;

use App\Enums\Tprm\ExitPlanStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\ExitTest;
use App\Models\Tprm\Finding;
use App\Models\Tprm\TierPolicy;
use App\Services\Tprm\Findings\FindingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Exit plans, their tests, and what a stale one costs — AC-11.
 *
 * "A Critical engagement whose exit plan was last tested 13 months ago
 * (policy 12) shows `stale`, raises a High finding, and adds `SU = 4`."
 *
 * THE THREE CONSEQUENCES ARE ONE ACT, and that is why they live in one method
 * rather than three places that each notice staleness. A status set by a
 * nightly job, a finding raised by a different job and a score uplift computed
 * live would disagree with each other the moment one of them failed — and the
 * one that fails silently is the score.
 *
 * THE INTERVAL COMES FROM THE TIER POLICY, not from a constant. "Critical is
 * tested annually" is a decision a risk committee owns; the module's job is to
 * enforce whatever they decided, and to fall back to the shipped default only
 * where they have not decided anything.
 */
class ExitPlanService
{
    /** Where a tier policy sets no interval. */
    public const DEFAULT_TEST_MONTHS = 12;

    public function __construct(private readonly FindingService $findings) {}

    /**
     * Record a test and move the clock forward.
     *
     * A FAILED TEST RESETS THE INTERVAL TOO. The interval is about how
     * recently the plan was exercised, not how well it went; the gaps become
     * findings in their own right. A bank that had to re-test immediately
     * after every failure would stop recording failures.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordTest(ExitPlan $plan, array $attributes, ?int $userId = null): ExitTest
    {
        return DB::transaction(function () use ($plan, $attributes, $userId): ExitTest {
            $test = ExitTest::create([
                'organization_id' => $plan->organization_id,
                'exit_plan_id' => $plan->getKey(),
                'created_by' => $userId,
            ] + $attributes);

            $testedOn = $test->test_date ?? Carbon::now();

            $plan->forceFill([
                'last_tested_at' => $testedOn->toDateString(),
                'next_test_due' => $testedOn->copy()
                    ->addMonths($this->intervalMonths($plan))
                    ->toDateString(),
                // A tested plan is no longer stale, whatever the outcome.
                'status' => ExitPlanStatus::Tested->value,
            ])->save();

            $this->advanceStalenessFinding($plan, $test, $userId);

            EngagementScoreInvalidated::dispatch(
                $plan->engagement,
                'exit_plan_tested',
            );

            return $test;
        });
    }

    /**
     * Mark every overdue plan stale, with the finding it owes.
     *
     * @return array{marked: int, findings: int}
     */
    public function markStale(?int $organizationId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $plans = ExitPlan::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('next_test_due')
            ->whereDate('next_test_due', '<', $asOf->toDateString())
            ->whereNotIn('status', [
                ExitPlanStatus::Stale->value,
                ExitPlanStatus::Invoked->value,
                ExitPlanStatus::Completed->value,
                ExitPlanStatus::NotRequired->value,
            ])
            ->with('engagement')
            ->get();

        $marked = 0;
        $raised = 0;

        foreach ($plans as $plan) {
            if ($plan->engagement === null) {
                continue;
            }

            $plan->forceFill(['status' => ExitPlanStatus::Stale->value])->save();
            $marked++;

            if ($this->raiseStalenessFinding($plan, $asOf)) {
                $raised++;
            }

            // The SU uplift is derived live from the plan's status; the event
            // is what makes the score reflect it now rather than tomorrow.
            EngagementScoreInvalidated::dispatch($plan->engagement, 'exit_plan_stale');
        }

        return ['marked' => $marked, 'findings' => $raised];
    }

    /**
     * The High finding a stale plan owes — AC-11's second consequence.
     */
    private function raiseStalenessFinding(ExitPlan $plan, Carbon $asOf): bool
    {
        $months = $plan->last_tested_at === null
            ? null
            : (int) $plan->last_tested_at->diffInMonths($asOf);

        $finding = $this->findings->raise(
            $plan->engagement,
            'monitoring',
            FindingSeverity::High,
            'Exit plan is past its test interval',
            [
                'source_id' => $plan->getKey(),
                'description' => sprintf(
                    "The exit plan for this engagement was due to be tested by %s and has not been.%s\n\n"
                    .'An untested exit plan is a document about exiting rather than a demonstrated ability to '
                    ."exit, and the residual risk carries an uplift while it stands.\n\nClose this by running "
                    .'a test — desktop counts — and recording it with its outcome and any gaps found.',
                    $plan->next_test_due?->toFormattedDateString() ?? 'an unrecorded date',
                    $months === null
                        ? ' It has never been tested.'
                        : sprintf(' It was last tested %d months ago.', $months),
                ),
                'regulatory_citation' => 'DORA Art. 28(8); CBN Outsourcing Guidelines',
            ],
        );

        return $finding->wasRecentlyCreated;
    }

    /**
     * Move the staleness finding into verification when the plan is tested.
     *
     * IT DOES NOT CLOSE IT, AND THAT IS DELIBERATE. `FindingService::close()`
     * refuses a remediated High finding without a named verifier and attached
     * evidence, and those guards are the whole reason the remediation register
     * means anything — "the work was described, not checked" is exactly the
     * failure they exist to prevent. A service closing its own findings to
     * keep its own screen tidy would be the first exception, and every
     * subsequent one would cite it.
     *
     * So the test advances the finding to `evidence_submitted`, where it lands
     * in a verifier's queue with the exercise recorded against it. The SU
     * uplift goes immediately regardless — that is derived from the plan's
     * test date, not from the finding — so the score is right even while the
     * paperwork catches up.
     *
     * Matched on SOURCE and source id rather than on the title alone, because
     * the title is prose and somebody will improve it.
     */
    private function advanceStalenessFinding(ExitPlan $plan, ExitTest $test, ?int $userId): void
    {
        Finding::query()
            ->where('engagement_id', $plan->engagement_id)
            ->where('source', 'monitoring')
            ->where('source_id', $plan->getKey())
            ->whereIn('status', ['open', 'assigned', 'in_remediation'])
            ->get()
            ->each(function (Finding $finding) use ($test, $userId): void {
                /*
                 * `recordPlan` first, because the status machine will not let
                 * an open finding jump to verification — a finding has to be
                 * assigned and worked before its evidence means anything. The
                 * test IS the remediation, so recording it as the plan is not
                 * a formality: it puts what was actually done on the finding.
                 */
                $this->findings->recordPlan(
                    $finding,
                    sprintf(
                        'The exit plan was tested on %s (%s). Outcome: %s.%s',
                        $test->test_date?->toFormattedDateString() ?? 'an unrecorded date',
                        str_replace('_', ' ', (string) $test->test_type),
                        str_replace('_', ' ', (string) ($test->outcome ?? 'not recorded')),
                        $test->gaps_identified === null || $test->gaps_identified === []
                            ? ''
                            : ' Gaps found: '.implode('; ', (array) $test->gaps_identified),
                    ),
                    null,
                    $userId,
                );

                $this->findings->submitForVerification(
                    $finding->refresh(),
                    $test->evidence_document_id,
                    $userId,
                );
            });
    }

    /**
     * The test interval in force for this plan's engagement.
     */
    public function intervalMonths(ExitPlan $plan): int
    {
        $tier = $plan->engagement?->effective_tier;

        if ($tier === null) {
            return self::DEFAULT_TEST_MONTHS;
        }

        $policy = TierPolicy::query()
            ->where('organization_id', $plan->organization_id)
            ->where('tier', $tier->value)
            ->first();

        return (int) ($policy?->exit_test_frequency_months ?: self::DEFAULT_TEST_MONTHS);
    }

    /**
     * Whether this engagement must have an exit plan before it can activate.
     *
     * TWO GROUNDS, EITHER SUFFICIENT: the tier policy requires one, or the
     * engagement supports a critical or important business function. The
     * second is the one that catches a Medium-tier engagement holding up
     * clearing — tier is about the vendor, criticality is about what the bank
     * would lose.
     *
     * @return array{required: bool, basis: string|null}
     */
    public function requirement(Engagement $engagement): array
    {
        if ($engagement->supports_critical_function) {
            return [
                'required' => true,
                'basis' => 'This engagement supports a critical or important business function.',
            ];
        }

        $policy = TierPolicy::query()
            ->where('organization_id', $engagement->organization_id)
            ->where('tier', $engagement->effective_tier?->value)
            ->first();

        if ($policy?->exit_plan_required) {
            return [
                'required' => true,
                'basis' => sprintf(
                    'The tier policy for %s engagements requires an exit plan.',
                    $engagement->effective_tier?->label() ?? 'this tier',
                ),
            ];
        }

        // The shipped default, for a tenant that has not written a policy.
        if (in_array($engagement->effective_tier, [RiskTier::Critical, RiskTier::High], true)) {
            return [
                'required' => true,
                'basis' => sprintf(
                    'A %s engagement requires an exit plan.',
                    $engagement->effective_tier->label(),
                ),
            ];
        }

        return ['required' => false, 'basis' => null];
    }
}
