<?php

namespace App\Services\Quantification;

use App\Models\IcaapAssessment;
use App\Models\QuantificationSetting;
use App\Models\SimulationRun;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The ICAAP screen's capital figures (migration Phase 5.2).
 *
 * Lifted whole out of QuantificationController::icaap(), which was 170 lines
 * of capital arithmetic inside a controller. Nothing here is changed: the
 * comments, the null handling and the resolution order are the ones WP-08
 * wrote when it removed the fabricated figures the August 2026 audit found —
 * a Pillar 2A column sliced 0.3/0.25/0.25/0.2 and presented as four risk
 * types, and five stress scenarios with hardcoded CAR drops independent of the
 * bank's balance sheet. Characterisation/IcaapCharacterisationTest pins every
 * figure this returns and was written before the extraction.
 *
 * THE RULE THAT RUNS THROUGH ALL OF IT: an absent input stays absent. An
 * unrecorded balance sheet is not a balance sheet of zeroes, and 0% CAR is a
 * specific, catastrophic claim about a bank's solvency — the one number this
 * screen must never invent.
 */
class IcaapService
{
    /**
     * The VaR columns MonteCarloService actually writes on a simulation
     * result, mapped to the confidence level each one represents.
     *
     * Stress impact is reported off THIS map rather than off the run's
     * requested `confidence_levels`, because the two disagree: a run defaults
     * to asking for [95, 99, 99.5] but the engine stores no 99.5 column, and
     * SimulationRun::getVar995Attribute() answers a request for "99.5" with
     * the 99.9 figure. Reporting a 99.9 loss under a 99.5 heading is the kind
     * of mislabel this work package exists to remove, so the screen states the
     * levels that were genuinely computed.
     */
    private const STRESS_VAR_COLUMNS = [
        'var_90_kobo' => 90.0,
        'var_95_kobo' => 95.0,
        'var_99_kobo' => 99.0,
        'var_99_9_kobo' => 99.9,
    ];

    /**
     * Everything the ICAAP screen reports, for the most recent assessment.
     *
     * @return array<string, mixed>
     */
    public function report(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $assessment = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        $minimumCar = $this->resolveMinimumCar($assessment, $orgId);
        $conservationBuffer = $this->resolveConservationBuffer($assessment, $orgId);

        // `icaap_assessments.cbn_minimum_car` is NOT NULL with a database
        // default of 10.0, so an assessment saved without an explicit minimum
        // still resolves to 10.0 and the organisation's standing figure is
        // never reached. That is the right precedence — an assessment is
        // reconciled against the minimum it was prepared under — but it is a
        // trap for a bank that set 15.0 org-wide and expected it to apply
        // retrospectively. Where the two disagree, the screen says so instead
        // of quietly preferring one.
        $organizationMinimumCar = QuantificationSetting::where('organization_id', $orgId)
            ->value('cbn_minimum_car');
        $organizationMinimumCar = $organizationMinimumCar === null ? null : (float) $organizationMinimumCar;

        // Stored inputs. Absent stays absent — an unrecorded balance sheet is
        // not a balance sheet of zeroes.
        $rwaKobo = $assessment?->total_rwa_kobo;
        $capitalKobo = $assessment?->total_qualifying_capital_kobo;
        $cet1Kobo = $assessment?->cet1_capital_kobo;
        $tier1Kobo = $assessment?->tier1_capital_kobo;
        $tier2Kobo = $assessment?->tier2_capital_kobo;

        $totalCapital = $this->naira($capitalKobo);
        $totalRwa = $this->naira($rwaKobo);
        $cet1Capital = $this->naira($cet1Kobo);
        $tier1Capital = $this->naira($tier1Kobo);
        $tier2Capital = $this->naira($tier2Kobo);

        // Ratios: capital / RWA * 100, null whenever RWA is missing or zero.
        $carComputed = $this->capitalRatioPercent($capitalKobo, $rwaKobo);
        $cet1Ratio = $this->capitalRatioPercent($cet1Kobo, $rwaKobo);
        $tier1Ratio = $this->capitalRatioPercent($tier1Kobo, $rwaKobo);

        // The preparer's own figure, kept separate and reconciled against ours.
        $carReported = ($assessment !== null && $assessment->car_actual !== null)
            ? round((float) $assessment->car_actual, 2)
            : null;

        $carVariance = ($carComputed !== null && $carReported !== null)
            ? round($carComputed - $carReported, 2)
            : null;

        $carVarianceMaterial = $carVariance !== null
            && abs($carVariance) > (float) config('quantification.car_reconciliation_tolerance');

        // Pillar 1 — the regulatory minimum charge against RWA. Computable
        // from stored quantities, so it is computed rather than borrowed from
        // the Pillar 2A columns as it used to be.
        $pillar1RequirementKobo = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? ($minimumCar / 100) * (float) $rwaKobo
            : null;
        $pillar1Requirement = $this->naira($pillar1RequirementKobo);

        // Pillar 2A — the ICAAP add-on, exactly as stored. No decomposition.
        $pillar2aCredit = $this->naira($assessment?->pillar2a_credit_kobo);
        $pillar2aMarket = $this->naira($assessment?->pillar2a_market_kobo);
        $pillar2aOperational = $this->naira($assessment?->pillar2a_operational_kobo);
        $pillar2aOther = $this->naira($assessment?->pillar2a_other_kobo);

        $pillar2aComponents = array_filter(
            [$pillar2aCredit, $pillar2aMarket, $pillar2aOperational, $pillar2aOther],
            fn ($v) => $v !== null,
        );
        $totalPillar2a = $pillar2aComponents === [] ? null : round(array_sum($pillar2aComponents), 2);

        // Pillar 2B — the stress buffer, as stored.
        $pillar2bStressBuffer = $this->naira($assessment?->pillar2b_stress_buffer_kobo);

        // Capital conservation buffer: a percentage of total RWA held in CET1,
        // not a percentage of qualifying capital as the old waterfall assumed.
        $conservationBufferAmount = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? $this->naira(($conservationBuffer / 100) * (float) $rwaKobo)
            : null;

        // Stress testing. Only from a bound run, only real arithmetic.
        $stressSimulation = null;
        $stressRows = collect();
        $stressAggregateMissing = false;

        if ($assessment !== null && $assessment->stress_simulation_id) {
            // Tenancy: the ICAAP row is already org-scoped, and the bound run
            // is re-scoped here so a mis-set foreign key can never surface
            // another organisation's loss distribution on this screen.
            $stressSimulation = SimulationRun::where('organization_id', $orgId)
                ->whereKey($assessment->stress_simulation_id)
                ->first();

            if ($stressSimulation !== null) {
                $stressRows = $this->stressImpactRows($stressSimulation, $assessment, $minimumCar);
                $stressAggregateMissing = $stressRows->isEmpty();
            }
        }

        $stressHeadlineConfidence = (float) config('quantification.headline_stress_confidence');
        $stressHeadline = $stressRows->firstWhere('confidence', $stressHeadlineConfidence) ?? $stressRows->last();

        // Capital waterfall, rebuilt from the corrected quantities. A missing
        // component stays null so Chart.js leaves a gap: a zero bar here would
        // read as "this bank has no Pillar 2A add-on", which is a claim, not
        // an absence of data.
        $waterfallComponents = [
            'Total Qualifying Capital' => $totalCapital,
            'Pillar 1 Requirement' => $pillar1Requirement === null ? null : -$pillar1Requirement,
            'Pillar 2A Add-on' => $totalPillar2a === null ? null : -$totalPillar2a,
            'Pillar 2B Stress Buffer' => $pillar2bStressBuffer === null ? null : -$pillar2bStressBuffer,
            'Conservation Buffer' => $conservationBufferAmount === null ? null : -$conservationBufferAmount,
        ];

        $waterfallMissing = array_keys(array_filter($waterfallComponents, fn ($v) => $v === null));

        // Available capital is only meaningful once every deduction is known.
        $availableCapital = $waterfallMissing === []
            ? round(array_sum($waterfallComponents), 2)
            : null;

        $waterfallData = [
            'labels' => [...array_keys($waterfallComponents), 'Available Capital'],
            'values' => [...array_values($waterfallComponents), $availableCapital],
        ];

        return [
            'assessment' => $assessment,
            'hasAssessment' => $assessment !== null,
            'minimumCar' => $minimumCar,
            'organizationMinimumCar' => $organizationMinimumCar,
            'internationalMinimumCar' => (float) config('quantification.international_or_dsib_minimum_car'),
            'conservationBuffer' => $conservationBuffer,
            'conservationBufferAmount' => $conservationBufferAmount,
            'totalCapital' => $totalCapital,
            'totalRwa' => $totalRwa,
            'cet1Capital' => $cet1Capital,
            'tier1Capital' => $tier1Capital,
            'tier2Capital' => $tier2Capital,
            'carComputed' => $carComputed,
            'carReported' => $carReported,
            'carVariance' => $carVariance,
            'carVarianceMaterial' => $carVarianceMaterial,
            'cet1Ratio' => $cet1Ratio,
            'tier1Ratio' => $tier1Ratio,
            'pillar1Requirement' => $pillar1Requirement,
            'pillar2aCredit' => $pillar2aCredit,
            'pillar2aMarket' => $pillar2aMarket,
            'pillar2aOperational' => $pillar2aOperational,
            'pillar2aOther' => $pillar2aOther,
            'totalPillar2a' => $totalPillar2a,
            'pillar2bStressBuffer' => $pillar2bStressBuffer,
            'stressSimulation' => $stressSimulation,
            'stressRows' => $stressRows,
            'stressHeadline' => $stressHeadline,
            'stressAggregateMissing' => $stressAggregateMissing,
            'availableCapital' => $availableCapital,
            'waterfallData' => $waterfallData,
            'waterfallMissing' => $waterfallMissing,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Resolve the minimum Capital Adequacy Ratio, in percent, that this
     * organisation is to be measured against.
     *
     * REGULATORY BASIS. The CBN Guidelines on Regulatory Capital (September
     * 2021) set the minimum CAR at 10.0% of total risk-weighted assets for
     * banks on a national or regional authorisation, and 15.0% for banks on an
     * international authorisation and for Domestic Systemically Important
     * Banks (D-SIBs). A capital conservation buffer of 1.0% of total RWA is
     * required on top, to be met with CET1, and a D-SIB carries a further
     * higher-loss-absorbency surcharge of 1.0%, also met with CET1.
     *
     * A bank on international authorisation, or one designated a D-SIB, MUST
     * configure 15.0 — either on the assessment (`cbn_minimum_car`) or as the
     * organisation's standing figure in Quantification Settings. Nothing here
     * infers authorisation class or D-SIB status, and nothing here rewrites a
     * stored figure; an assessment is always measured against the minimum it
     * was prepared against, which is what a validator reconciles to.
     *
     * THE DEFECT (WP-08). This used to be `$icaap->car_required ?? 10`.
     * `car_required` is not a column on icaap_assessments and never has been,
     * so the null coalesce fired on every single row and the screen showed a
     * hardcoded 10% to every tenant — including the international banks and
     * D-SIBs for whom the answer is 15%. Meanwhile
     * `quantification_settings.cbn_minimum_car`, which the settings screen
     * lets an org edit, was read by nothing at all. Both are now resolved
     * here, in one place, in a stated order.
     */
    public function resolveMinimumCar(?IcaapAssessment $assessment, ?int $organizationId = null): float
    {
        if ($assessment !== null && $assessment->cbn_minimum_car !== null && (float) $assessment->cbn_minimum_car > 0) {
            return (float) $assessment->cbn_minimum_car;
        }

        $organizationId ??= TenantContext::organizationId();

        $setting = QuantificationSetting::where('organization_id', $organizationId)->first();

        if ($setting !== null && $setting->cbn_minimum_car !== null && (float) $setting->cbn_minimum_car > 0) {
            return (float) $setting->cbn_minimum_car;
        }

        return (float) config('quantification.default_minimum_car');
    }

    /**
     * Resolve the capital conservation buffer, in percent of total RWA.
     *
     * Same resolution order and same rule as the minimum CAR: whatever the
     * assessment was prepared against wins, then the organisation's standing
     * figure, then the CBN default of 1.0%. The previous code fell back to a
     * hardcoded 2.5 — the Basel III figure, not the Nigerian one.
     */
    public function resolveConservationBuffer(?IcaapAssessment $assessment, ?int $organizationId = null): float
    {
        if ($assessment !== null && $assessment->conservation_buffer !== null && (float) $assessment->conservation_buffer > 0) {
            return (float) $assessment->conservation_buffer;
        }

        $organizationId ??= TenantContext::organizationId();

        $setting = QuantificationSetting::where('organization_id', $organizationId)->first();

        if ($setting !== null && $setting->cbn_conservation_buffer !== null && (float) $setting->cbn_conservation_buffer > 0) {
            return (float) $setting->cbn_conservation_buffer;
        }

        return (float) config('quantification.default_conservation_buffer');
    }

    /**
     * A capital ratio in percent, or null when it cannot be computed.
     *
     * NULL, never 0. A bank with no RWA on file has an UNKNOWN CAR, and 0% is
     * a specific, catastrophic claim about a bank's solvency — it is the one
     * number the screen must never invent. Every caller passes the null
     * through to the view so it can say "Not assessed".
     */
    public function capitalRatioPercent(int|float|null $capitalKobo, int|float|null $rwaKobo): ?float
    {
        if ($capitalKobo === null || $rwaKobo === null || (float) $rwaKobo <= 0.0) {
            return null;
        }

        return round(((float) $capitalKobo / (float) $rwaKobo) * 100, 2);
    }

    /**
     * Kobo → Naira, preserving null.
     */
    public function naira(int|float|null $kobo): ?float
    {
        return $kobo === null ? null : round((float) $kobo / 100, 2);
    }

    /**
     * The capital a stress run consumes, and what the balance sheet looks like
     * afterwards, at every confidence level the engine genuinely computed.
     *
     * THE DEFECT (WP-08). Both the ICAAP screen and the stress report used to
     * invent scenario names ('Severe Recession', 'Oil Price Shock', 'Cyber
     * Attack + Market Crash', ...) and subtract hardcoded CAR drops of 3.5,
     * 2.1, 2.8, 5.2 and 1.6 percentage points, optionally scaled by an
     * invented `factor`. Those drops were independent of the bank's capital,
     * its RWA and its portfolio mix — the same five numbers appeared for every
     * tenant — and no bank had ever defined those scenarios. They are gone.
     *
     * What is reported instead is arithmetic on stored quantities only:
     *
     *     capital impact = the run's aggregate VaR at confidence level c
     *     capital after  = total qualifying capital - capital impact
     *     CAR after      = capital after / total RWA * 100
     *     shortfall      = max(0, (minimum CAR / 100) * total RWA - capital after)
     *     verdict        = CAR after >= minimum CAR
     *
     * One row per confidence level the run stored, each labelled with the
     * level it came from, rather than one row per invented scenario name.
     * Anything that cannot be computed is null and stays null.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function stressImpactRows(SimulationRun $stressRun, IcaapAssessment $assessment, float $minimumCar): \Illuminate\Support\Collection
    {
        $aggregate = $stressRun->aggregate_result;

        if (! $aggregate) {
            return collect();
        }

        $capitalKobo = $assessment->total_qualifying_capital_kobo;
        $rwaKobo = $assessment->total_rwa_kobo;

        $requiredCapitalKobo = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? ($minimumCar / 100) * (float) $rwaKobo
            : null;

        $rows = collect();

        foreach (self::STRESS_VAR_COLUMNS as $column => $confidence) {
            $lossKobo = $aggregate->{$column};

            if ($lossKobo === null) {
                continue;
            }

            $capitalAfterKobo = $capitalKobo === null
                ? null
                : (float) $capitalKobo - (float) $lossKobo;

            $carAfter = $this->capitalRatioPercent($capitalAfterKobo, $rwaKobo);

            $shortfallKobo = ($requiredCapitalKobo !== null && $capitalAfterKobo !== null)
                ? max(0.0, $requiredCapitalKobo - $capitalAfterKobo)
                : null;

            $rows->push((object) [
                'confidence' => $confidence,
                'basis' => 'Aggregate VaR at '.rtrim(rtrim(number_format($confidence, 1), '0'), '.').'% confidence',
                'capital_impact' => $this->naira($lossKobo),
                'capital_after' => $this->naira($capitalAfterKobo),
                'car_after' => $carAfter,
                'shortfall' => $this->naira($shortfallKobo),
                'meets_minimum' => $carAfter === null ? null : $carAfter >= $minimumCar,
            ]);
        }

        return $rows;
    }
}
