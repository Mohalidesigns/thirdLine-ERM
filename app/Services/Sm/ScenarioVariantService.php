<?php

namespace App\Services\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioVariant;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service managing severe-but-plausible scenario variants (ADR 0027 Amendment 3 §A3.2).
 */
class ScenarioVariantService
{
    /**
     * Ensure the scenario has the 3 escalating default tier records seeded.
     *
     * @return Collection<int, ScenarioVariant>
     */
    public function ensureDefaultVariants(Scenario $scenario): Collection
    {
        $existing = $scenario->variants()->get();
        if ($existing->count() >= 3) {
            return $existing;
        }

        $existingTiers = $existing->pluck('tier')->all();

        foreach (ScenarioVariant::TIERS as $index => $tier) {
            if (! in_array($tier, $existingTiers, true)) {
                ScenarioVariant::create([
                    'organization_id' => $scenario->organization_id,
                    'sm_scenario_id' => $scenario->id,
                    'tier' => $tier,
                    'name' => ScenarioVariant::DEFAULT_TIER_NAMES[$tier] ?? 'Tier '.($index + 1),
                    'narrative' => null,
                    'is_cumulative' => $tier !== ScenarioVariant::TIER_1,
                    'triggers' => [],
                    'operational_impacts' => [],
                    'responses' => [],
                    'response_owners' => [],
                    'sort_order' => $index + 1,
                ]);
            }
        }

        return $scenario->variants()->get();
    }

    /**
     * Save the variant deck for a scenario.
     *
     * @param  array<int, array<string, mixed>>  $variantsData
     * @return Collection<int, ScenarioVariant>
     *
     * @throws ScenarioRefused
     */
    public function saveVariants(Scenario $scenario, array $variantsData, User $actor): Collection
    {
        if ($scenario->isFrozen()) {
            throw new ScenarioRefused("Scenario {$scenario->reference} is {$scenario->status} and cannot be edited.");
        }

        return DB::transaction(function () use ($scenario, $variantsData) {
            foreach ($variantsData as $item) {
                $tier = $item['tier'] ?? null;
                if (! in_array($tier, ScenarioVariant::TIERS, true)) {
                    continue;
                }

                $triggers = is_array($item['triggers'] ?? null)
                    ? array_values(array_filter($item['triggers'], fn ($v) => filled(trim((string) $v))))
                    : [];

                $impacts = is_array($item['operational_impacts'] ?? null)
                    ? array_values(array_filter($item['operational_impacts'], fn ($v) => filled(trim((string) $v))))
                    : [];

                $responses = is_array($item['responses'] ?? null)
                    ? array_values(array_filter($item['responses'], fn ($v) => filled(trim((string) $v))))
                    : [];

                $owners = is_array($item['response_owners'] ?? null)
                    ? array_values(array_filter($item['response_owners'], fn ($v) => filled(trim((string) $v))))
                    : [];

                ScenarioVariant::updateOrCreate(
                    [
                        'organization_id' => $scenario->organization_id,
                        'sm_scenario_id' => $scenario->id,
                        'tier' => $tier,
                    ],
                    [
                        'name' => (string) ($item['name'] ?? ScenarioVariant::DEFAULT_TIER_NAMES[$tier]),
                        'narrative' => filled($item['narrative'] ?? null) ? (string) $item['narrative'] : null,
                        'is_cumulative' => (bool) ($item['is_cumulative'] ?? ($tier !== ScenarioVariant::TIER_1)),
                        'triggers' => $triggers,
                        'operational_impacts' => $impacts,
                        'responses' => $responses,
                        'response_owners' => $owners,
                        'sort_order' => match ($tier) {
                            ScenarioVariant::TIER_1 => 1,
                            ScenarioVariant::TIER_2 => 2,
                            ScenarioVariant::TIER_3 => 3,
                            default => 1,
                        },
                    ]
                );
            }

            AuditTrailService::record(
                $scenario,
                'variants_updated',
                'variants',
                null,
                count($variantsData)
            );

            return $scenario->variants()->get();
        });
    }
}
