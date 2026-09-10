<?php

namespace App\Services\Quantification;

use App\Models\QuantificationScenario;
use App\Support\Quantification\Distributions;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The operational-risk scenario library (migration Phase 5.2).
 *
 * WHAT MOVED AND WHY. The ten templates were a PHP literal inside
 * QuantificationController — ten `(object)` casts on ten very long lines, in
 * the middle of a 1,611-line controller. They are `config/quantification_library
 * .php` now, which is what Phase 5's acceptance criterion 4 asks for and what
 * makes the provenance auditable: every entry carries a `source`, and
 * QuantificationLibraryTest fails if one does not.
 *
 * That matters more here than it looks. A library template's mean and standard
 * deviation become a lognormal severity distribution, which MonteCarloService
 * draws on, which produces an aggregate VaR, which becomes a Pillar 2B stress
 * buffer in an ICAAP submission. A parameter that entered that chain without a
 * stated origin would reach a regulator with none.
 *
 * THEY ARE TEMPLATES, NOT THE BANK'S NUMBERS, and the import says so in the
 * scenario's own description so the provenance travels with the record rather
 * than living only in this file.
 */
class ScenarioLibrary
{
    /**
     * Every template, as objects — the shape the screens have always read.
     *
     * @return Collection<int, \stdClass>
     */
    public function all(): Collection
    {
        return collect(array_values(config('quantification_library.scenarios', [])))
            ->map(fn (array $entry) => (object) $entry);
    }

    public function find(string $id): ?object
    {
        return $this->all()->firstWhere('id', $id);
    }

    /**
     * Has this organisation already imported a template?
     *
     * Matched on name, as it always has been: a template has no identity in
     * the tenant's own tables once imported, and re-importing one would give
     * the bank two scenarios with the same parameters and different references.
     */
    public function importedScenario(object $template, ?int $organizationId = null): ?QuantificationScenario
    {
        return QuantificationScenario::where('organization_id', $organizationId ?? TenantContext::organizationId())
            ->where('name', $template->name)
            ->first();
    }

    /**
     * The attributes a template becomes when imported.
     *
     * Naira in the config, kobo in the columns — the conversion happens once,
     * here, through Distributions::lognormalFromMoments().
     *
     * @return array<string, mixed>
     */
    public function attributesFor(object $template, string $scenarioReference, ?int $organizationId = null): array
    {
        $frequency = (float) $template->frequency_per_year;

        [$mu, $sigma, $meanKobo] = Distributions::lognormalFromMoments(
            (float) $template->mean,
            (float) $template->std_dev,
        );

        return [
            'organization_id' => $organizationId ?? TenantContext::organizationId(),
            'scenario_reference' => $scenarioReference,
            // NOT NULL with no default, and absent from this array until Phase
            // 5.2 — so importing ANY of the ten templates ended in a 500. The
            // library screen offered them and not one could be taken.
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'name' => $template->name,
            // The provenance travels with the record, not just with this file.
            'description' => $template->description." (Imported from library — source: {$template->source})",
            'cbn_risk_category' => $template->risk_category,
            'severity_distribution' => $template->distribution_type,
            'frequency_distribution' => 'poisson',
            'frequency_lambda' => $frequency,
            'expected_annual_frequency' => $frequency,
            'severity_mu' => round($mu, 6),
            'severity_sigma' => round($sigma, 6),
            'expected_loss_per_event_kobo' => $meanKobo,
            'expected_annual_loss_kobo' => round($meanKobo * $frequency),
            'status' => 'active',
            'created_by' => auth()->id(),
        ];
    }

    /**
     * The next SCN-YYYY-NNN reference for this organisation.
     */
    public function nextReference(?int $organizationId = null): string
    {
        $year = now()->year;

        $last = QuantificationScenario::where('organization_id', $organizationId ?? TenantContext::organizationId())
            ->where('scenario_reference', 'like', "SCN-{$year}-%")
            ->orderByDesc('scenario_reference')
            ->first();

        $next = $last ? ((int) substr($last->scenario_reference, -3)) + 1 : 1;

        return sprintf('SCN-%d-%03d', $year, $next);
    }
}
