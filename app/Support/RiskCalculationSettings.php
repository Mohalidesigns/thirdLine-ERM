<?php

namespace App\Support;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * Resolves the tunable inputs to the risk calculations: the control
 * effectiveness bands and the impact aggregation method.
 *
 * Defaults live in config/risk.php. An organization overrides them through its
 * `settings` JSON column under a `risk` key, e.g.
 *
 *     {"risk": {"impact_aggregation": "worst_two",
 *               "control_effectiveness": {"effective": 90}}}
 *
 * Overrides are merged over the defaults rather than replacing them, so an
 * organisation that wants to adjust one band does not have to restate the
 * other four — and a band added in a later release still has a value for
 * every tenant.
 *
 * Reads are memoised per organization for the life of the request. These
 * values are consulted once per control per risk during a bulk recalculation;
 * without this the settings row would be fetched thousands of times.
 */
class RiskCalculationSettings
{
    /** @var array<int|string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, int|float>
     */
    public static function effectivenessMap(?int $organizationId = null): array
    {
        $defaults = config('risk.control_effectiveness');
        $override = self::organizationSettings($organizationId)['control_effectiveness'] ?? [];

        return is_array($override) ? array_merge($defaults, $override) : $defaults;
    }

    /**
     * The gross loss, in naira, at or above which a loss event is flagged
     * `is_regulatory_reportable` when the reporter has not answered
     * (migration Phase 4.3).
     *
     * This was a bare literal (NGN 10 million) inside LossEventController::store().
     */
    public static function regulatoryReportableThresholdNgn(?int $organizationId = null): float
    {
        $default = (float) config('risk.regulatory_reportable_threshold_ngn');
        $override = self::organizationSettings($organizationId)['regulatory_reportable_threshold_ngn'] ?? null;

        return is_numeric($override) ? (float) $override : $default;
    }

    /**
     * One of: max, average, weighted, worst_two.
     *
     * An unrecognised value falls back to the configured default rather than
     * throwing — a typo in a tenant's settings blob should not take their risk
     * register offline — but it is logged so it gets fixed.
     */
    public static function impactAggregation(?int $organizationId = null): string
    {
        $default = (string) config('risk.impact_aggregation', 'max');
        $method = self::organizationSettings($organizationId)['impact_aggregation'] ?? $default;

        if (! in_array($method, ['max', 'average', 'weighted', 'worst_two'], true)) {
            logger()->warning('Unknown impact aggregation method; falling back to the default', [
                'organization_id' => $organizationId,
                'method' => $method,
                'default' => $default,
            ]);

            return $default;
        }

        return $method;
    }

    /**
     * @return array<string, float>
     */
    public static function impactWeights(?int $organizationId = null): array
    {
        $defaults = config('risk.impact_weights');
        $override = self::organizationSettings($organizationId)['impact_weights'] ?? [];

        return array_map('floatval', is_array($override) ? array_merge($defaults, $override) : $defaults);
    }

    /**
     * Drop the memoised settings. Called from tests, and after an
     * organization's settings are edited.
     */
    public static function flush(?int $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$organizationId]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizationSettings(?int $organizationId): array
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return [];
        }

        if (array_key_exists($organizationId, self::$cache)) {
            return self::$cache[$organizationId];
        }

        $settings = Organization::query()
            ->whereKey($organizationId)
            ->value('settings');

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return self::$cache[$organizationId] = (is_array($settings) ? $settings['risk'] ?? [] : []);
    }
}
