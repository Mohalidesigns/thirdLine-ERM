<?php

namespace App\Services\Quantification;

use App\Models\QuantificationScenario;
use App\Support\Quantification\Distributions;
use App\Support\Tenancy\TenantContext;

/**
 * Scenario register rules (migration Phase 5.2).
 *
 * The reference sequence, the Naira → kobo + lognormal-parameter conversion
 * both write paths share, and the distribution curve the show page draws,
 * lifted out of QuantificationController.
 *
 * A scenario's mean and standard deviation become a lognormal severity
 * distribution, MonteCarloService draws on it, the run produces an aggregate
 * VaR and that VaR becomes a capital add-on in a regulatory submission. The
 * conversion is therefore the load-bearing part of this file and lives in
 * {@see Distributions}, under its own test.
 */
class ScenarioService
{
    /**
     * Severity distributions a user is allowed to choose.
     *
     * WP-08. The form used to offer six — lognormal, normal, poisson, pareto,
     * weibull, beta — and the validator accepted all six. MonteCarloService
     * has exactly one severity draw, lognormalRandom(), and calls it
     * unconditionally. Choosing "Pareto" therefore stored the string 'pareto'
     * and then simulated a lognormal, so a scenario calibrated for a heavy
     * tail was quantified with a light one and nothing on screen said so. On
     * an ICAAP tail measure that is not a cosmetic difference.
     *
     * normal, poisson, pareto, weibull and beta come back to this list when —
     * and only when — MonteCarloService implements a draw for them. Offering a
     * distribution the engine cannot run is worse than not offering it: the
     * user gets a number, it just is not the number they asked for.
     */
    public const SUPPORTED_SEVERITY_DISTRIBUTIONS = ['lognormal'];

    /**
     * Validation for both write paths. The create and edit forms post the same
     * field names; only `status` is edit-only.
     *
     * @return array<string, string>
     */
    public function rules(bool $forUpdate = false): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'risk_category' => 'required|string|max:100',
            'linked_risk_id' => 'nullable|exists:risks,id',
            'distribution_type' => 'required|in:'.implode(',', self::SUPPORTED_SEVERITY_DISTRIBUTIONS),
            'frequency_per_year' => 'required|numeric|min:0',
            'mean' => 'required|numeric|min:0',
            'std_dev' => 'nullable|numeric|min:0',
            'min_loss' => 'nullable|numeric|min:0',
            'max_loss' => 'nullable|numeric|min:0',
        ];

        if ($forUpdate) {
            $rules['status'] = 'nullable|in:draft,active,archived';
        }

        return $rules;
    }

    /**
     * The next reference in this organisation's SCN-YYYY-NNN sequence.
     */
    public function nextReference(?int $organizationId = null): string
    {
        $orgId = $organizationId ?? TenantContext::organizationId();
        $year = now()->year;

        $last = QuantificationScenario::where('organization_id', $orgId)
            ->where('scenario_reference', 'like', "SCN-{$year}-%")
            ->orderByDesc('scenario_reference')
            ->first();

        $nextNumber = $last ? ((int) substr($last->scenario_reference, -3)) + 1 : 1;

        return sprintf('SCN-%d-%03d', $year, $nextNumber);
    }

    /**
     * Create a scenario from the form's field names.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $organizationId = null, ?int $userId = null): QuantificationScenario
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        return QuantificationScenario::create(array_merge([
            'organization_id' => $orgId,
            'scenario_reference' => $this->nextReference($orgId),
            // NOT NULL with no default. Until Phase 5.2 this key was absent
            // and every submission of this form ended in a 500.
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'frequency_distribution' => 'poisson',
            'status' => 'active',
            'created_by' => $userId ?? auth()->id(),
        ], $this->attributesFrom($input)));
    }

    /**
     * Update a scenario from the same field names.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(QuantificationScenario $scenario, array $input): QuantificationScenario
    {
        $scenario->update(array_merge($this->attributesFrom($input), [
            'status' => $input['status'] ?? $scenario->status,
        ]));

        return $scenario;
    }

    /**
     * The form's Naira-and-moments vocabulary mapped to the columns the table
     * actually has, with the lognormal conversion applied once.
     *
     * `risk_register_id` is the column; the form calls it `linked_risk_id`.
     * The severity bounds are Naira on the form and kobo in the table.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributesFrom(array $input): array
    {
        $freqYear = (float) ($input['frequency_per_year'] ?? 0);

        [$mu, $sigma, $meanKobo] = Distributions::lognormalFromMoments(
            (float) ($input['mean'] ?? 0),
            (float) ($input['std_dev'] ?? 0),
        );

        return [
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
            'cbn_risk_category' => $input['risk_category'] ?? null,
            'risk_register_id' => $input['linked_risk_id'] ?? null,
            'severity_distribution' => $input['distribution_type'] ?? null,
            'frequency_lambda' => $freqYear,
            'expected_annual_frequency' => $freqYear,
            'severity_mu' => round($mu, 6),
            'severity_sigma' => round($sigma, 6),
            'expected_loss_per_event_kobo' => $meanKobo,
            'expected_annual_loss_kobo' => round($meanKobo * $freqYear),
            'severity_min_kobo' => $this->kobo($input['min_loss'] ?? null),
            'severity_max_kobo' => $this->kobo($input['max_loss'] ?? null),
        ];
    }

    /** Naira → kobo, keeping "not stated" distinct from zero. */
    private function kobo(mixed $naira): ?float
    {
        return $naira ? round((float) $naira * 100) : null;
    }

    /**
     * A scenario in the FORM's vocabulary — the exact inverse of
     * attributesFrom().
     *
     * THIS IS THE FIX FOR A DEFECT THAT SPANNED FOUR SCREENS. The scenario
     * list, the show page, the simulate picker and the edit form all read
     * `risk_category`, `distribution_type`, `mean`, `std_dev`,
     * `frequency_per_year`, `min_loss`, `max_loss` and `last_run_at` straight
     * off the model. NOT ONE of those is a column on `quantification_scenarios`
     * — they are the create form's field names, and the write path has always
     * had to translate them (that is what attributesFrom() is for). Every read
     * sat behind `?? 0` or `?? '-'`, so nothing ever failed:
     *
     *   - the register listed every scenario as category "-", distribution "-",
     *     mean ₦0, std dev ₦0, "-/year", last run "Never";
     *   - the show page's four headline tiles were ₦0, ₦0, 0/year and "-",
     *     printed beside a correctly-parameterised log-normal curve drawn from
     *     the very parameters the tiles claimed were zero;
     *   - the simulate picker offered each scenario as "· · Mean: ₦0", giving
     *     an operator assembling a capital run nothing to choose on.
     *
     * These are the parameters MonteCarloService draws to produce the VaR that
     * becomes a Pillar 2B buffer, so ₦0 is the same class of claim as 0% CAR.
     *
     * `last_run_at` is not resurrected: no such column has ever existed, and
     * the runs that included a scenario are a query, not a field. The show page
     * lists them.
     *
     * @return array<string, mixed>
     */
    public function toFormValues(QuantificationScenario $scenario): array
    {
        $mean = $this->naira($scenario->expected_loss_per_event_kobo);

        return [
            'name' => $scenario->name,
            'description' => $scenario->description,
            'risk_category' => $scenario->cbn_risk_category,
            'linked_risk_id' => $scenario->risk_register_id,
            'distribution_type' => $scenario->severity_distribution ?? self::SUPPORTED_SEVERITY_DISTRIBUTIONS[0],
            'frequency_per_year' => $scenario->expected_annual_frequency === null
                ? null
                : (float) $scenario->expected_annual_frequency,
            'mean' => $mean,
            // `severity_sigma` is cast decimal:6, so it arrives as a string.
            'std_dev' => Distributions::stdDevFromLognormal(
                $mean,
                $scenario->severity_sigma === null ? null : (float) $scenario->severity_sigma,
            ),
            'min_loss' => $this->naira($scenario->severity_min_kobo),
            'max_loss' => $this->naira($scenario->severity_max_kobo),
            'expected_annual_loss' => $this->naira($scenario->expected_annual_loss_kobo),
            'status' => $scenario->status,
        ];
    }

    /**
     * A scenario as the register, the picker and the show page list it: its
     * identity plus the form-vocabulary figures above.
     *
     * @return array<string, mixed>
     */
    public function toListRow(QuantificationScenario $scenario): array
    {
        return array_merge([
            'id' => $scenario->id,
            'scenario_reference' => $scenario->scenario_reference,
        ], $this->toFormValues($scenario));
    }

    /** Kobo → Naira, keeping "not stated" distinct from zero. */
    private function naira(int|float|null $kobo): ?float
    {
        return $kobo === null ? null : round((float) $kobo / 100, 2);
    }

    /**
     * Build visualization data for a lognormal distribution.
     *
     * WP-08 note on the mu fallback below. When a scenario has no stored
     * severity_mu this uses log(mean) WITHOUT the -sigma^2/2 correction that
     * Distributions::lognormalFromMoments() applies, so the curve drawn for such a
     * scenario has a mean of mean * exp(sigma^2/2) rather than mean.
     *
     * That is deliberate and it is left alone. MonteCarloService::runSimulation
     * uses the identical fallback (`log(max($scenario->expected_loss_per_event_kobo ?? 1e8, 1))`,
     * sigma 1.5) when a scenario has no stored parameters, and this chart's job
     * is to show the distribution the engine will actually draw from — not a
     * different, better one. Correcting it here alone would put the picture and
     * the simulation out of step, which is how the two halves of this screen
     * disagreed in the first place. The fallback belongs to the engine and has
     * to be fixed there; the parameters WRITTEN by this service are already
     * correct, so any scenario created or edited since WP-08 never reaches it.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function distributionVisualization(QuantificationScenario $scenario): array
    {
        $mu = (float) ($scenario->severity_mu ?? log(max($scenario->expected_loss_per_event_kobo ?? 100000000, 1)));
        $sigma = (float) ($scenario->severity_sigma ?? 1.5);

        // Generate ~20 buckets for the PDF of a lognormal
        $meanVal = exp($mu + ($sigma ** 2) / 2);
        $maxX = $meanVal * 3;
        $step = $maxX / 20;

        $labels = [];
        $values = [];

        for ($x = $step; $x <= $maxX; $x += $step) {
            if ($x > 0) {
                $pdf = (1 / ($x * $sigma * sqrt(2 * M_PI))) * exp(-(log($x) - $mu) ** 2 / (2 * $sigma ** 2));
                $labels[] = '₦'.number_format(round($x / 100, 0));
                $values[] = round($pdf * $step, 6);
            }
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
