<?php

namespace App\Services\Scoring;

use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Support\Str;

/**
 * Gives an organisation a scoring profile of its own.
 *
 * WHY THIS EXISTS. Before WP-05 the scoring inputs a tenant could change lived
 * in organizations.settings — `risk.impact_aggregation` and `risk.impact_weights`
 * were read live by the service, while `risk_settings.probability_scale` and
 * `risk_settings.impact_scale` were written by the admin screen and read by
 * nothing at all. The profile is now the single definition. This class is the
 * one place that translates the old surface into the new one, so the settings
 * screen, the tenant-onboarding path and the seed migration cannot drift apart.
 *
 * THE CONTRACT. settings.risk.* seeds a profile when the organisation does not
 * yet have one. After that the profile is authoritative and settings.risk.* is
 * no longer consulted by any calculation — otherwise a tenant who edits their
 * matrix in the builder would have it silently reverted by a stale JSON key
 * nobody remembered was there. The settings screen therefore writes through to
 * the profile rather than only to the JSON.
 */
class ScoringProfileProvisioner
{
    /**
     * The organisation's own default profile, creating it from the platform
     * template and the organisation's settings if it has none.
     *
     * Idempotent: safe to call on every settings save and every login.
     */
    public function ensureFor(Organization $organization): ScoringProfile
    {
        $existing = $this->ownDefault($organization);

        if ($existing !== null) {
            return $existing;
        }

        $settings = (array) ($organization->settings ?? []);
        $risk = is_array($settings['risk'] ?? null) ? $settings['risk'] : [];

        $aggregation = $risk['impact_aggregation'] ?? config('risk.impact_aggregation', 'max');

        if (! in_array($aggregation, ['max', 'weighted', 'average', 'worst_two'], true)) {
            $aggregation = (string) config('risk.impact_aggregation', 'max');
        }

        $weights = array_map('floatval', array_merge(
            (array) config('risk.impact_weights', []),
            is_array($risk['impact_weights'] ?? null) ? $risk['impact_weights'] : [],
        ));

        // The scale the admin screen has been collecting all along. This is the
        // first code path that has ever honoured it, and it only does so at
        // provisioning time — see the class docblock.
        $declared = is_array($settings['risk_settings'] ?? null) ? $settings['risk_settings'] : [];
        $rows = $this->axis($declared['probability_scale'] ?? null);
        $cols = $this->axis($declared['impact_scale'] ?? null);

        $currency = (string) ($settings['reporting_currency'] ?? 'NGN');

        return ScoringProfile::create(ScoringProfileTemplates::default($currency, [
            'organization_id' => $organization->id,
            'code' => 'default',
            'name' => $rows === 5 && $cols === 5 ? 'Default 5×5' : "Default {$rows}×{$cols}",
            'description' => 'Provisioned from this organisation\'s risk settings.',
            'likelihood_scale' => ScoringProfileTemplates::likelihoodScale($rows),
            'impact_scale' => ScoringProfileTemplates::impactScale($cols, $currency),
            'impact_aggregation' => $aggregation,
            'dimension_weights' => $weights,
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor($rows, $cols),
            'matrix_rows' => $rows,
            'matrix_cols' => $cols,
            'is_default' => true,
            'is_system' => false,
        ]));
    }

    /**
     * Resize an organisation's default profile, rescaling its bands and scales.
     *
     * Called by the settings screen and the builder. Returns the profile so the
     * caller can report what the new bands are — a resize re-rates the whole
     * register, and a user is entitled to see the new boundaries before they
     * discover them on a dashboard.
     */
    public function resize(Organization $organization, int $rows, int $cols): ScoringProfile
    {
        $profile = $this->ensureFor($organization);

        $rows = $this->axis($rows);
        $cols = $this->axis($cols);

        $currency = (string) (($organization->settings ?? [])['reporting_currency'] ?? 'NGN');

        $profile->fill([
            // Existing level definitions are preserved where the new scale is
            // long enough to keep them: a tenant who wrote "loss above ₦2bn"
            // against impact 5 should not lose that text because they added a
            // sixth point.
            'likelihood_scale' => $this->rescale($profile->likelihood_scale ?? [], ScoringProfileTemplates::likelihoodScale($rows)),
            'impact_scale' => $this->rescale($profile->impact_scale ?? [], ScoringProfileTemplates::impactScale($cols, $currency)),
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor($rows, $cols),
            'matrix_rows' => $rows,
            'matrix_cols' => $cols,
        ])->save();

        ScoringProfile::flushResolutionCache();

        return $profile;
    }

    /**
     * Merge what the tenant has already written into a freshly sized scale.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $template
     * @return list<array<string, mixed>>
     */
    private function rescale(array $existing, array $template): array
    {
        $byValue = [];

        foreach ($existing as $level) {
            $byValue[(int) ($level['value'] ?? 0)] = $level;
        }

        return array_map(function (array $level) use ($byValue) {
            $previous = $byValue[(int) $level['value']] ?? null;

            if ($previous === null) {
                return $level;
            }

            // Keep everything the tenant authored; take only the value from the
            // template, which is the one field the resize owns.
            return array_merge($level, array_filter(
                $previous,
                fn ($value, $key) => $key !== 'value' && $value !== null && $value !== '',
                ARRAY_FILTER_USE_BOTH
            ));
        }, $template);
    }

    private function ownDefault(Organization $organization): ?ScoringProfile
    {
        return ScoringProfile::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** Matrices below 3×3 say nothing and above 10×10 nobody can read. */
    private function axis(mixed $value): int
    {
        $value = (int) ($value ?: 5);

        return max(3, min(10, $value));
    }

    /**
     * A unique code for a new profile within an organisation.
     */
    public function codeFor(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'profile';
        $code = $base;
        $suffix = 1;

        while (ScoringProfile::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->exists()
        ) {
            $code = $base.'-'.(++$suffix);
        }

        return $code;
    }
}
