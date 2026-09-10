<?php

use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-05 TASK 3 — migrate every existing organisation onto a profile that
 * reproduces exactly what it scored yesterday.
 *
 * A migration, not a seeder, for the reason 120005 gives: the code that reads
 * these rows ships in the same release, and a seeder is optional. An install
 * that skipped it would resolve no profile for any tenant.
 *
 * TWO KINDS OF ROW GET WRITTEN.
 *
 *   The system profile (organization_id NULL) is the seeded 5×5. It is what a
 *   newly created organisation scores against before anyone configures
 *   anything, and it is the row the parity test pins.
 *
 *   A tenant profile is written ONLY for an organisation whose settings JSON
 *   already deviates from the config defaults under `risk.impact_aggregation`
 *   or `risk.impact_weights`. Those were live inputs to
 *   RiskScoringService::calculateImpact() before this release; if the service
 *   starts reading profiles and the deviation is not carried across, that
 *   organisation's impact scores move on upgrade. Organisations that never
 *   customised anything get no row and resolve to the system profile.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. organizations.settings->risk_settings
 * carries `probability_scale` and `impact_scale` integers that the admin
 * screen has been collecting since WP-00 and that nothing has ever read. It is
 * tempting to honour them here. Doing so would silently re-rate the entire
 * register of any organisation that once typed 4 into a box that did nothing —
 * a 4×4 matrix tops out at 16, so every risk previously rated Critical would
 * land somewhere else on the morning of the upgrade, with no user action and
 * no audit event. Parity wins. Those values become live through the settings
 * screen and the profile builder, on an explicit save, from this release
 * onward; the migration logs which organisations are affected so somebody can
 * go and ask them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('scoring_profiles')->insert($this->row(
            ScoringProfileTemplates::default('NGN'),
            organizationId: null,
            now: $now,
        ));

        $configAggregation = (string) config('risk.impact_aggregation', 'max');
        $configWeights = array_map('floatval', (array) config('risk.impact_weights', []));

        $divergentScales = [];

        foreach (DB::table('organizations')->get(['id', 'name', 'settings']) as $organization) {
            $settings = json_decode($organization->settings ?? '{}', true);
            $settings = is_array($settings) ? $settings : [];

            $risk = is_array($settings['risk'] ?? null) ? $settings['risk'] : [];
            $currency = $settings['reporting_currency'] ?? 'NGN';

            $aggregation = $risk['impact_aggregation'] ?? $configAggregation;

            if (! in_array($aggregation, ['max', 'weighted', 'average', 'worst_two'], true)) {
                // RiskCalculationSettings falls back on an unrecognised value
                // rather than throwing, so the profile must too — otherwise a
                // typo that was harmless yesterday becomes a scoring change.
                $aggregation = $configAggregation;
            }

            $weights = is_array($risk['impact_weights'] ?? null)
                ? array_map('floatval', array_merge($configWeights, $risk['impact_weights']))
                : $configWeights;

            // Record, for the log, anyone whose dead scale setting disagrees
            // with the 5×5 they are about to be migrated onto.
            $declared = is_array($settings['risk_settings'] ?? null) ? $settings['risk_settings'] : [];
            $rows = (int) ($declared['probability_scale'] ?? 5);
            $cols = (int) ($declared['impact_scale'] ?? 5);

            if (($rows !== 5 && $rows > 0) || ($cols !== 5 && $cols > 0)) {
                $divergentScales[] = [
                    'organization_id' => $organization->id,
                    'name' => $organization->name,
                    'declared_matrix' => "{$rows}×{$cols}",
                ];
            }

            $deviates = $aggregation !== $configAggregation
                || $this->weightsDiffer($weights, $configWeights);

            if (! $deviates) {
                continue;
            }

            DB::table('scoring_profiles')->insert($this->row(
                ScoringProfileTemplates::default($currency, [
                    'code' => 'default-5x5',
                    'name' => 'Default 5×5',
                    'description' => 'Migrated from this organisation\'s risk calculation settings so that '
                        .'scores are unchanged by the move to scoring profiles.',
                    'impact_aggregation' => $aggregation,
                    'dimension_weights' => $weights,
                    'is_system' => false,
                ]),
                organizationId: (int) $organization->id,
                now: $now,
            ));
        }

        if ($divergentScales !== []) {
            logger()->warning(
                'Scoring profiles: these organisations had a non-5×5 matrix saved in settings->risk_settings, '
                .'which nothing has ever read. They have been migrated onto the 5×5 profile so their existing '
                .'ratings are unchanged. Resize their profile in the scoring builder to honour the declared shape.',
                ['organizations' => $divergentScales]
            );
        }
    }

    public function down(): void
    {
        // Only rows this migration could have written. A profile a tenant has
        // since created through the builder is not this migration's to delete.
        DB::table('scoring_profiles')->where('code', 'default-5x5')->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function row(array $payload, ?int $organizationId, mixed $now): array
    {
        foreach (['applies_to', 'likelihood_scale', 'impact_scale', 'impact_dimensions', 'dimension_weights', 'rating_bands'] as $key) {
            $payload[$key] = $payload[$key] === null ? null : json_encode($payload[$key]);
        }

        return $payload + [
            'organization_id' => $organizationId,
            'effective_from' => null,
            'approved_by' => null,
            'approved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    private function weightsDiffer(array $a, array $b): bool
    {
        if (array_keys($a) !== array_keys($b)) {
            return true;
        }

        foreach ($a as $key => $value) {
            if (abs($value - ($b[$key] ?? 0.0)) > 0.00001) {
                return true;
            }
        }

        return false;
    }
};
