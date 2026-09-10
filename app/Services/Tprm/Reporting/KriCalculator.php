<?php

namespace App\Services\Tprm\Reporting;

use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\Finding;
use App\Services\Tprm\Graph\ConcentrationAnalyzer;
use App\Support\Tprm\KriCatalogue;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The nine third-party KRI readings — FR-RPT-10.
 *
 * A METRIC THAT CANNOT BE COMPUTED RETURNS NULL, AND NULL IS NOT ZERO. This is
 * the whole design. "Percentage of Critical vendors assessed within cadence"
 * over an estate with no Critical vendors has no value: publishing 100% tells
 * the board the programme is perfect, publishing 0% tells them it has failed,
 * and both are statements about an empty set. The publisher skips a null and
 * records why, so the KRI shows its last real reading and its own staleness
 * rather than a fresh number that means nothing.
 *
 * THAT MATTERS MORE HERE THAN ANYWHERE ELSE IN THE MODULE, because a KRI
 * feeds breach detection. A fabricated 0% on an empty denominator would open a
 * red breach, notify an owner and reach a board pack, all from a division by
 * nothing.
 *
 * Each method returns `[value, note]`: the reading, and what a reader needs in
 * order to interpret it — usually the denominator.
 */
class KriCalculator
{
    public function __construct(private readonly ConcentrationAnalyzer $concentration) {}

    /**
     * Every metric, computed.
     *
     * @return array<string, array{value: ?float, note: string}>
     */
    public function all(): array
    {
        $readings = [];

        foreach (KriCatalogue::codes() as $code) {
            $readings[$code] = $this->compute($code);
        }

        return $readings;
    }

    /**
     * @return array{value: ?float, note: string}
     */
    public function compute(string $code): array
    {
        return match ($code) {
            'critical_assessed_within_cadence' => $this->criticalAssessedWithinCadence(),
            'contracts_with_blocking_clauses' => $this->contractsWithBlockingClauses(),
            'average_finding_age' => $this->averageFindingAge(),
            'overdue_findings' => $this->overdueFindings(),
            'expired_mandatory_evidence' => $this->expiredEvidence(),
            'concentration_index' => $this->concentrationIndex(),
            'exit_plan_test_currency' => $this->exitPlanTestCurrency(),
            'mean_vendor_response_time' => $this->meanVendorResponseTime(),
            'assurance_depth' => $this->assuranceDepth(),
            default => ['value' => null, 'note' => "No calculator is defined for [{$code}]."],
        };
    }

    /* ================================================================== */

    /**
     * @return array{value: ?float, note: string}
     */
    private function criticalAssessedWithinCadence(): array
    {
        $critical = $this->liveEngagements()
            ->filter(fn (Engagement $e) => $e->effective_tier === RiskTier::Critical);

        if ($critical->isEmpty()) {
            return $this->undeterminable('No engagement is tiered Critical, so there is no population to measure.');
        }

        // "Within cadence" is `next_assessment_due` still in the future. The
        // due date is set from the tier policy when an assessment validates,
        // so an engagement that has never been assessed has none — and is
        // counted as out of cadence rather than excluded, which is the whole
        // point of the indicator.
        $current = $critical->filter(
            fn (Engagement $e) => $e->next_assessment_due !== null && $e->next_assessment_due->isFuture()
        );

        return [
            'value' => round($current->count() / $critical->count() * 100, 1),
            'note' => sprintf(
                '%d of %d Critical engagements. %d have no assessment cadence set at all and count as out of '
                .'cadence.',
                $current->count(),
                $critical->count(),
                $critical->whereNull('next_assessment_due')->count(),
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function contractsWithBlockingClauses(): array
    {
        $executed = Contract::query()->where('status', 'executed')->get();

        if ($executed->isEmpty()) {
            return $this->undeterminable('No contract is recorded as executed.');
        }

        // `blocking_gaps_count` is the denormalised count the register list
        // reads; it counts gaps that are absent or partial WITHOUT a waiver,
        // so a waived gap already counts as covered here.
        $clear = $executed->filter(fn (Contract $contract) => (int) $contract->blocking_gaps_count === 0);

        return [
            'value' => round($clear->count() / $executed->count() * 100, 1),
            'note' => sprintf(
                '%d of %d executed contracts carry no unwaived blocking gap. Read beside the waiver register: '
                .'a waived gap counts as covered.',
                $clear->count(),
                $executed->count(),
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function averageFindingAge(): array
    {
        $open = $this->openFindings();

        if ($open->isEmpty()) {
            // Not zero. Zero days would read as "every finding was closed the
            // day it was raised", which is a different and much better claim
            // than "there are none open".
            return $this->undeterminable('No third-party finding is open.');
        }

        $ages = $open->map(fn (Finding $finding) => (int) $finding->identified_at->diffInDays(now()));

        return [
            'value' => round($ages->avg(), 1),
            'note' => sprintf('Across %d open findings, measured from identification.', $open->count()),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function overdueFindings(): array
    {
        $overdue = $this->openFindings()->filter(
            fn (Finding $finding) => $finding->target_date !== null && $finding->target_date->isPast()
        );

        // A count is computable over an empty set: zero overdue findings is a
        // true and useful statement, unlike a zero average age.
        return [
            'value' => (float) $overdue->count(),
            'note' => sprintf(
                '%d of %d open findings are past their remediation date.',
                $overdue->count(),
                $this->openFindings()->count(),
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function expiredEvidence(): array
    {
        $count = Document::query()
            ->expired()
            ->whereHas('documentType', fn ($query) => $query->where('is_assurance_evidence', true))
            ->count();

        return [
            'value' => (float) $count,
            'note' => $count === 0
                ? 'No assurance document is past its valid-to date.'
                : $count.' assurance documents have expired; each has already decayed the assurance '
                    .'coefficient of whatever it evidenced.',
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function concentrationIndex(): array
    {
        $result = $this->concentration->analyse((int) TenantContext::organizationId());

        $hhi = $result['hhi'] ?? null;

        if (! is_numeric($hhi)) {
            return $this->undeterminable('The concentration analysis produced no index.');
        }

        $clusters = count($result['clusters'] ?? []);

        if ($clusters === 0) {
            return $this->undeterminable('No provider group is on the register, so there is nothing to '
                .'concentrate.');
        }

        return [
            'value' => round((float) $hhi, 1),
            'note' => sprintf(
                'Across %d provider groups — %s.',
                $clusters,
                strtolower($this->concentration->bandLabel((float) $hhi)),
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function exitPlanTestCurrency(): array
    {
        $required = $this->liveEngagements()->filter(
            fn (Engagement $e) => (bool) $e->exit_plan_required || $e->effective_tier === RiskTier::Critical
        );

        if ($required->isEmpty()) {
            return $this->undeterminable('No engagement requires an exit plan.');
        }

        $plans = ExitPlan::query()
            ->whereIn('engagement_id', $required->map(fn (Engagement $e) => $e->getKey())->all())
            ->get();

        // The denominator is engagements that REQUIRE a plan, not plans that
        // exist. A ratio over the plans written flatters a programme that has
        // written three and needs thirty.
        $current = $plans->filter(
            fn (ExitPlan $plan) => $plan->hasBeenTested() && ! $plan->testIsOverdue()
        );

        return [
            'value' => round($current->count() / $required->count() * 100, 1),
            'note' => sprintf(
                '%d of %d engagements requiring a plan have one tested within its interval. %d have no plan at '
                .'all.',
                $current->count(),
                $required->count(),
                $required->count() - $plans->count(),
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function meanVendorResponseTime(): array
    {
        $submitted = Assessment::query()
            ->whereNotNull('issued_at')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', now()->subMonths(12))
            ->get();

        if ($submitted->isEmpty()) {
            return $this->undeterminable('No assessment was submitted in the last twelve months.');
        }

        $days = $submitted->map(
            fn (Assessment $assessment) => (float) $assessment->issued_at->diffInDays($assessment->submitted_at)
        );

        $outstanding = Assessment::query()
            ->whereNotNull('issued_at')
            ->whereNull('submitted_at')
            ->count();

        return [
            'value' => round($days->avg(), 1),
            'note' => sprintf(
                'Across %d assessments submitted in the last twelve months. %d issued assessments are still '
                .'outstanding and are NOT in this mean — an assessment nobody returned is an absent response, '
                .'not a slow one.',
                $submitted->count(),
                $outstanding,
            ),
        ];
    }

    /**
     * @return array{value: ?float, note: string}
     */
    private function assuranceDepth(): array
    {
        $population = $this->liveEngagements()->filter(fn (Engagement $e) => in_array(
            $e->effective_tier,
            [RiskTier::Critical, RiskTier::High],
            true
        ));

        if ($population->isEmpty()) {
            return $this->undeterminable('No engagement is tiered Critical or High.');
        }

        $scored = $population->filter(fn (Engagement $e) => $e->evidence_confidence !== null);

        if ($scored->isEmpty()) {
            // The distinction that matters most on this metric: an estate
            // nobody has scored has no assurance depth, and reporting one
            // would be the exact assertion-over-evidence failure the metric
            // exists to detect.
            return $this->undeterminable(sprintf(
                'None of the %d Critical or High engagements carries an evidence coefficient, so there is no '
                .'assurance to measure the depth of.',
                $population->count(),
            ));
        }

        return [
            'value' => round((float) $scored->avg(fn (Engagement $e) => (float) $e->evidence_confidence), 3),
            'note' => sprintf(
                'Across %d of %d Critical and High engagements; %d carry no evidence coefficient and are '
                .'excluded rather than counted as zero.',
                $scored->count(),
                $population->count(),
                $population->count() - $scored->count(),
            ),
        ];
    }

    /* ================================================================== */

    /**
     * @return array{value: null, note: string}
     */
    private function undeterminable(string $why): array
    {
        return ['value' => null, 'note' => $why];
    }

    /** @var Collection<int, Engagement>|null */
    private ?Collection $engagementCache = null;

    /**
     * @return Collection<int, Engagement>
     */
    private function liveEngagements(): Collection
    {
        return $this->engagementCache ??= Engagement::query()
            ->get()
            ->filter(fn (Engagement $engagement) => $engagement->status->isLive())
            ->values();
    }

    /** @var Collection<int, Finding>|null */
    private ?Collection $findingCache = null;

    /**
     * @return Collection<int, Finding>
     */
    private function openFindings(): Collection
    {
        return $this->findingCache ??= Finding::query()
            ->get()
            ->filter(fn (Finding $finding) => $finding->status->isOpen())
            ->values();
    }
}
