<?php

namespace App\Services\Quantification;

use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\QuantificationScenario;
use App\Models\Risk;
use App\Models\SimulationRun;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The four quantification reports (migration Phase 5.2).
 *
 * Capital Adequacy Summary, Stress Testing, Risk Contribution Analysis and the
 * Regulatory Compliance Pack, lifted whole out of QuantificationController.
 * Nothing here is changed:
 * Characterisation/QuantificationReportsCharacterisationTest pins every figure
 * and was written against the running Blade screens before the extraction.
 *
 * These four are what a bank prints for its board and files with the CBN. The
 * capital arithmetic they share with the ICAAP screen — resolveMinimumCar,
 * capitalRatioPercent, naira, stressImpactRows — stays in {@see IcaapService}
 * so the reports and the screen cannot drift apart, and the long WP-08 notes
 * on the methods below travel with the code because they are the record of
 * what the August 2026 audit found and why it is gone.
 */
class QuantificationReportService
{
    public function __construct(private readonly IcaapService $icaap) {}

    /**
     * Capital Adequacy Summary — condensed view of the latest ICAAP: CAR,
     * tier breakdown, Pillar 1 requirement, Pillar 2A/2B demand, headroom.
     *
     * WP-08. This report carried the same two defects as the ICAAP screen and
     * is corrected the same way: the `pillar2a_*` columns were being printed
     * under a "Pillar 1 — Minimum Capital Requirements" heading, and the
     * regulatory minimum came from `$icaap->car_required ?? 10` — a column
     * that does not exist, so every tenant saw 10%. Pillar 1 is now computed
     * as (minimum CAR / 100) x total RWA, and the minimum is resolved once in
     * IcaapService::resolveMinimumCar().
     *
     * @return array{d: object, hasData: bool}
     */
    public function capitalAdequacy(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $icaap = $this->latestAssessment($orgId);
        $minimumCar = $this->icaap->resolveMinimumCar($icaap, $orgId);

        $rwaKobo = $icaap?->total_rwa_kobo;
        $capitalKobo = $icaap?->total_qualifying_capital_kobo;

        $data = (object) [
            'as_of' => $icaap?->created_at,
            'total_capital' => $this->icaap->naira($capitalKobo),
            'total_rwa' => $this->icaap->naira($rwaKobo),
            'cet1' => $this->icaap->naira($icaap?->cet1_capital_kobo),
            'tier1' => $this->icaap->naira($icaap?->tier1_capital_kobo),
            'tier2' => $this->icaap->naira($icaap?->tier2_capital_kobo),
            'car_computed' => $this->icaap->capitalRatioPercent($capitalKobo, $rwaKobo),
            'car_reported' => $this->reportedCar($icaap),
            'cet1_ratio' => $this->icaap->capitalRatioPercent($icaap?->cet1_capital_kobo, $rwaKobo),
            'tier1_ratio' => $this->icaap->capitalRatioPercent($icaap?->tier1_capital_kobo, $rwaKobo),
            'car_required' => $minimumCar,
            'conservation_buffer' => $this->icaap->resolveConservationBuffer($icaap, $orgId),
            'pillar2a_credit' => $this->icaap->naira($icaap?->pillar2a_credit_kobo),
            'pillar2a_market' => $this->icaap->naira($icaap?->pillar2a_market_kobo),
            'pillar2a_operational' => $this->icaap->naira($icaap?->pillar2a_operational_kobo),
            'pillar2a_other' => $this->icaap->naira($icaap?->pillar2a_other_kobo),
            'pillar2b_buffer' => $this->icaap->naira($icaap?->pillar2b_stress_buffer_kobo),
        ];

        $this->addCapitalDemand($data, $minimumCar, $rwaKobo);
        $this->addCarReconciliation($data, $minimumCar);

        return ['d' => $data, 'hasData' => (bool) $icaap];
    }

    /**
     * Pillar 1, the Pillar 2A total and the headroom left after both.
     *
     * The partial-total rule is the point of this method: a sum over some of
     * the deductions would read as more headroom than the bank has.
     */
    private function addCapitalDemand(object $data, float $minimumCar, int|float|null $rwaKobo): void
    {
        // Pillar 1 minimum capital requirement = (minimum CAR / 100) x RWA.
        $data->pillar1_requirement = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? $this->icaap->naira(($minimumCar / 100) * (float) $rwaKobo)
            : null;

        $pillar2aParts = array_filter(
            [$data->pillar2a_credit, $data->pillar2a_market, $data->pillar2a_operational, $data->pillar2a_other],
            fn ($v) => $v !== null,
        );
        $data->total_pillar2a = $pillar2aParts === [] ? null : round(array_sum($pillar2aParts), 2);

        // Headroom is only meaningful once every deduction is known; a partial
        // total would read as more headroom than the bank has.
        $deductions = [$data->pillar1_requirement, $data->total_pillar2a, $data->pillar2b_buffer];
        $data->headroom = ($data->total_capital !== null && ! in_array(null, $deductions, true))
            ? round($data->total_capital - array_sum($deductions), 2)
            : null;
    }

    /**
     * Surplus against the minimum, and the preparer's own CAR reconciled
     * against the computed one rather than one quietly winning.
     */
    private function addCarReconciliation(object $data, float $minimumCar): void
    {
        $data->car_surplus = $data->car_computed === null
            ? null
            : round($data->car_computed - $minimumCar, 2);

        $data->car_variance = ($data->car_computed !== null && $data->car_reported !== null)
            ? round($data->car_computed - $data->car_reported, 2)
            : null;

        $data->car_variance_material = $data->car_variance !== null
            && abs($data->car_variance) > (float) config('quantification.car_reconciliation_tolerance');
    }

    /**
     * Stress Testing Report — capital impact of the stress simulation bound to
     * the latest ICAAP assessment, plus the stress scenarios this tenant has
     * actually defined.
     *
     * WP-08 rewrite. What was deleted, and why:
     *
     * 1. FIVE HARDCODED SCENARIOS. 'Severe Recession' (-3.50pp), 'Oil Price
     *    Shock' (-2.10pp), 'Naira Devaluation' (-2.80pp), 'Cyber Attack +
     *    Market Crash' (-5.20pp) and 'Liquidity Squeeze' (-1.60pp), each
     *    scaled by an invented `factor`. Those CAR deltas were literals: they
     *    did not depend on the bank's balance sheet, its RWA, its portfolio
     *    mix or its own scenario library, so every tenant of this product saw
     *    the same five numbers regardless of what they hold. A stress test
     *    whose result is independent of the thing being stressed is not a
     *    stress test.
     *
     * 2. THE "LATEST COMPLETED SIMULATION" FALLBACK. When no stress run was
     *    bound, the report picked up whatever simulation had finished most
     *    recently. That is how a single-scenario operational-risk run — an
     *    internal fraud calibration, say — ended up presented to a bank's
     *    board as a macroeconomic stress test. There is no honest way to guess
     *    which run the preparer intended, so the report no longer guesses: it
     *    reports on the run that was deliberately bound to the assessment, or
     *    it reports nothing and says what to do about it.
     *
     * 3. THE 'Marginal' VERDICT BAND. Pass at >= 10%, Marginal at >= 8%, Fail
     *    below. There is no 8% supervisory threshold in the CBN capital
     *    guidelines; it was invented. The verdict is now binary against the
     *    resolved minimum.
     *
     * What replaces them is the arithmetic in IcaapService::stressImpactRows():
     * one row per confidence level the bound run genuinely computed, each
     * stating its own confidence level, with capital impact, capital after
     * stress, CAR after stress and shortfall all derived from stored capital
     * and RWA.
     *
     * @return array<string, mixed>
     */
    public function stressTesting(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $icaap = $this->latestAssessment($orgId);
        $minimumCar = $this->icaap->resolveMinimumCar($icaap, $orgId);

        $stressSim = $this->boundStressRun($icaap, $orgId);

        $rows = ($stressSim !== null && $icaap !== null)
            ? $this->icaap->stressImpactRows($stressSim, $icaap, $minimumCar)
            : collect();

        return [
            'icaap' => $icaap,
            'rows' => $rows,
            'stressScenarios' => $this->stressScenarios($orgId, $stressSim),
            'stressSim' => $stressSim,
            'minimumCar' => $minimumCar,
            'totalCapital' => $this->icaap->naira($icaap?->total_qualifying_capital_kobo),
            'totalRwa' => $this->icaap->naira($icaap?->total_rwa_kobo),
            'carComputed' => $this->icaap->capitalRatioPercent(
                $icaap?->total_qualifying_capital_kobo,
                $icaap?->total_rwa_kobo,
            ),
            'carReported' => $this->reportedCar($icaap),
            'hasBoundRun' => $stressSim !== null,
            'hasRows' => $rows->isNotEmpty(),
        ];
    }

    /**
     * Only the deliberately bound run, and only this organisation's. See the
     * second deletion in stressTesting()'s note for why there is no fallback.
     */
    private function boundStressRun(?IcaapAssessment $icaap, int $orgId): ?SimulationRun
    {
        if ($icaap === null || ! $icaap->stress_simulation_id) {
            return null;
        }

        return SimulationRun::where('organization_id', $orgId)
            ->whereKey($icaap->stress_simulation_id)
            ->first();
    }

    /**
     * The stress scenarios this tenant has defined for itself, so the report
     * can show what was in scope of the bound run and what was not.
     *
     * A scenario is "stress" if it carries a CBN stress designation or was
     * typed as one — the two ways this schema records the flag.
     *
     * @return Collection<int, mixed>
     */
    private function stressScenarios(int $orgId, ?SimulationRun $stressSim): Collection
    {
        $runScenarioIds = collect($stressSim !== null ? $stressSim->scenario_ids : [])
            ->map(fn ($id) => (int) $id)->all();

        return QuantificationScenario::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->whereNotNull('cbn_stress_scenario')
                    ->orWhereRaw('LOWER(scenario_type) = ?', ['stress']);
            })
            ->orderBy('scenario_reference')
            ->get()
            ->map(fn ($scenario) => (object) [
                'reference' => $scenario->scenario_reference,
                'name' => $scenario->name,
                'category' => $scenario->cbn_risk_category,
                'cbn_stress_scenario' => $scenario->cbn_stress_scenario,
                'expected_annual_loss' => $this->icaap->naira($scenario->expected_annual_loss_kobo),
                'in_bound_run' => in_array((int) $scenario->id, $runScenarioIds, true),
            ]);
    }

    /**
     * Risk Contribution Analysis — where the modelled loss sits, by risk type
     * and by business unit.
     *
     * WP-08. TWO THINGS WERE CALLED "CAPITAL" THAT ARE NOT CAPITAL.
     *
     * 1. `round($group->sum('residual_score'), 2)`, aggregated by category and
     *    by business unit, was emitted under the key `capital` and rendered in
     *    a column headed "Capital / Score". A residual score is an ordinal
     *    point on a 1-25 matrix. Ordinal values are not additive — the
     *    distance from 4 to 6 is not the distance from 20 to 22 — and they are
     *    not denominated in Naira, so a sum of them is neither a capital
     *    number nor a quantity that supports the percentage shares computed
     *    from it. The rows are now labelled for what they are: a total of
     *    residual scores, with the count of risks behind it, and the view is
     *    told which basis it is rendering via $byTypeBasis / $byUnitBasis so
     *    it cannot print a naira sign in front of an ordinal total. Nothing is
     *    fabricated to replace it; a bank that wants capital by business unit
     *    needs a simulation scoped to business units, which this engine does
     *    not yet run.
     *
     * 2. `risk_contributions` / `scenario_contributions` off a simulation
     *    result is each scenario's share of EXPECTED ANNUAL LOSS — it is
     *    computed in MonteCarloService as scenario expected loss over total
     *    expected loss. It is NOT a component-VaR or Euler capital allocation:
     *    it says nothing about how each scenario contributes to the TAIL, and
     *    a scenario with a small mean and a fat tail is exactly the one this
     *    measure under-reports. It must not be presented as an allocation of
     *    economic capital. The by-type rows therefore carry the expected-loss
     *    basis explicitly and the view labels the column "Expected Annual
     *    Loss", not "Capital".
     *
     * @return array<string, mixed>
     */
    public function riskContribution(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $latestSim = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')->first();

        // By risk type — scenario contributions when a completed run exists.
        // 'expected_loss' basis: Naira, share of total expected annual loss.
        $byType = $latestSim ? $this->contributionsByScenario($latestSim) : collect();
        $byTypeBasis = $byType->isEmpty() ? 'residual_score' : 'expected_loss';

        // Fallback when no simulation has run: risk categories by residual
        // score. 'residual_score' basis: ordinal totals, NOT Naira.
        if ($byType->isEmpty()) {
            $byType = $this->residualScoreTotals($orgId, 'category');
        }

        // By business unit — always residual score; the engine has never
        // produced a business-unit loss distribution.
        $byUnit = $this->residualScoreTotals($orgId, 'businessUnit');

        return [
            'byType' => $byType,
            'byTypeBasis' => $byTypeBasis,
            'byUnit' => $byUnit,
            'byUnitBasis' => 'residual_score',
            'latestSim' => $latestSim,
            'hasData' => $byType->isNotEmpty() || $byUnit->isNotEmpty(),
        ];
    }

    /**
     * Each scenario's share of the run's total expected annual loss, in Naira.
     *
     * @return Collection<int, mixed>
     */
    private function contributionsByScenario(SimulationRun $run): Collection
    {
        $contributions = $run->scenario_contributions;

        if (! $contributions || $contributions->isEmpty()) {
            return collect();
        }

        return $contributions->map(fn ($c) => (object) [
            'label' => $c->scenario_name,
            'value' => (float) ($c->expected_loss ?? 0),
            'share_pct' => (float) ($c->contribution_pct ?? 0),
            'risks' => null,
        ])->sortByDesc('value')->values();
    }

    /**
     * Active risks grouped by a relation, totalled on residual score.
     *
     * ORDINAL TOTALS, NOT NAIRA — the caller must pass the basis flag through
     * to the view so it cannot print a currency sign in front of these.
     *
     * @return Collection<int, mixed>
     */
    private function residualScoreTotals(int $orgId, string $relation): Collection
    {
        $unlabelled = $relation === 'category' ? 'Uncategorised' : 'Unassigned';

        $rows = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with($relation)
            ->get()
            ->groupBy(fn (Risk $r) => optional($r->getRelationValue($relation))->name ?? $unlabelled)
            ->map(fn ($group, $label) => (object) [
                'label' => $label,
                'risks' => $group->count(),
                'value' => round($group->sum('residual_score'), 2),
            ])->values();

        $total = $rows->sum(fn (object $row) => $row->value);

        return $rows->map(function ($row) use ($total) {
            $row->share_pct = $total > 0 ? round(($row->value / $total) * 100, 2) : null;

            return $row;
        })->sortByDesc('value')->values();
    }

    /**
     * Regulatory Compliance Pack — combined reporting snapshot pulling
     * capital, KRI, loss-event, and issues data for a one-page regulatory
     * view aligned to CBN ORMS expectations.
     *
     * @return array<string, mixed>
     */
    public function regulatoryPack(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $icaap = $this->latestAssessment($orgId);

        $summary = $this->packSummary($icaap, $orgId);

        return [
            'summary' => $summary,
            'checklist' => $this->packChecklist($summary, $icaap),
            'icaap' => $icaap,
        ];
    }

    /**
     * The capital position and the operational counts the pack files.
     */
    private function packSummary(?IcaapAssessment $icaap, int $orgId): object
    {
        // WP-08. Was `$icaap->car_required ?? 10` against a column that does
        // not exist, so the pack filed to CBN always claimed a 10% minimum.
        $minimumCar = $this->icaap->resolveMinimumCar($icaap, $orgId);

        // WP-08. CAR is recomputed from stored capital and RWA; the preparer's
        // typed `car_actual` is only used when RWA is not on file, and the
        // checklist says which basis it used.
        $carComputed = $this->icaap->capitalRatioPercent(
            $icaap?->total_qualifying_capital_kobo,
            $icaap?->total_rwa_kobo,
        );
        $carReported = $this->reportedCar($icaap);

        $year = now()->year;

        return (object) [
            'car_actual' => $carComputed ?? $carReported,
            'car_computed' => $carComputed,
            'car_reported' => $carReported,
            'car_basis' => $carComputed !== null ? 'computed from capital / RWA' : 'as reported on the assessment',
            'car_required' => $minimumCar,
            'total_capital' => $this->icaap->naira($icaap?->total_qualifying_capital_kobo),
            'active_risks' => Risk::where('organization_id', $orgId)->where('status', 'active')->count(),
            'critical_risks' => Risk::where('organization_id', $orgId)->where('residual_rating', 'Critical')->count(),
            'high_risks' => Risk::where('organization_id', $orgId)->where('residual_rating', 'High')->count(),
            'red_kris' => KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'red')->count(),
            'amber_kris' => KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'amber')->count(),
            'loss_events_ytd' => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $year)->count(),
            'net_loss_ytd' => (float) LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $year)->sum(LossEvent::netLossNairaSql()),
            'open_issues' => Issue::where('organization_id', $orgId)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
            'overdue_issues' => Issue::where('organization_id', $orgId)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->where('remediation_due_date', '<', now())->count(),
            'regulatory_issues' => Issue::where('organization_id', $orgId)->where('regulatory_reportable', true)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
        ];
    }

    /**
     * Checklist of filing items with a simple pass/warning/fail indicator.
     *
     * The minimum is printed as resolved, not as a hardcoded "10%" — a bank on
     * international authorisation or designated a D-SIB is measured against
     * 15%, and this line is read as a compliance assertion.
     *
     * @return Collection<int, mixed>
     */
    private function packChecklist(object $summary, ?IcaapAssessment $icaap): Collection
    {
        return collect([
            ['item' => 'CAR above CBN minimum ('.rtrim(rtrim(number_format($summary->car_required, 2), '0'), '.').'%)',
                'status' => $summary->car_actual === null ? 'warning' : ($summary->car_actual >= $summary->car_required ? 'pass' : 'fail'),
                'detail' => $summary->car_actual === null
                    ? 'No capital position on record — CAR cannot be assessed'
                    : $summary->car_actual.'% ('.$summary->car_basis.') vs '.$summary->car_required.'% required'],
            ['item' => 'ICAAP submitted this cycle', 'status' => $icaap ? 'pass' : 'fail',
                'detail' => $icaap ? 'Last assessment: '.$icaap->created_at->format('d M Y') : 'No ICAAP on record'],
            ['item' => 'No critical residual risks', 'status' => $summary->critical_risks === 0 ? 'pass' : 'warning',
                'detail' => $summary->critical_risks.' critical residual risks open'],
            ['item' => 'KRI breaches under threshold', 'status' => $summary->red_kris === 0 ? 'pass' : 'warning',
                'detail' => $summary->red_kris.' red / '.$summary->amber_kris.' amber'],
            ['item' => 'Regulatory issues closed',  'status' => $summary->regulatory_issues === 0 ? 'pass' : 'fail',
                'detail' => $summary->regulatory_issues.' regulatory issues still open'],
            ['item' => 'No overdue issues',         'status' => $summary->overdue_issues === 0 ? 'pass' : 'warning',
                'detail' => $summary->overdue_issues.' overdue issues'],
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** Every report reports on the most recent assessment, or on none. */
    private function latestAssessment(int $orgId): ?IcaapAssessment
    {
        return IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();
    }

    /**
     * The CAR the preparer typed, kept separate from the computed one so the
     * two can be reconciled rather than one quietly winning.
     */
    private function reportedCar(?IcaapAssessment $icaap): ?float
    {
        return ($icaap !== null && $icaap->car_actual !== null)
            ? round((float) $icaap->car_actual, 2)
            : null;
    }
}
