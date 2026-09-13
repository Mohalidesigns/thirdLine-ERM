<?php

namespace App\Services\Tprm\Graph;

use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\ThirdParty;
use Illuminate\Support\Collection;

/**
 * Concentration across the portfolio — TRD §7.8 and FR-NTH-04.
 *
 * THE HHI IS OVER CRITICAL-FUNCTION DEPENDENCY, NOT OVER SPEND OR HEADCOUNT.
 * That distinction is the whole value. A bank whose largest supplier by spend
 * is a facilities-management company and whose payment switching sits with one
 * provider used by every other bank in the country has a concentration problem
 * in the second place and not the first, and a spend-weighted index would point
 * at the wrong one.
 *
 *   HHI = Σ (share × 100)²   over each provider group's share of critical
 *                            function dependencies
 *   <1500 diversified · 1500–2500 moderate · >2500 concentrated
 *
 * THE FOURTH-PARTY DIMENSION IS THE ONE NOBODY SHIPS. Six banks each using a
 * different core banking vendor look diversified until all six vendors host on
 * the same cloud region — and the `provider_group` dimension walks the
 * sub-processor graph to see that. Counting only direct suppliers measures the
 * paperwork rather than the exposure.
 *
 * A RUN IS A SNAPSHOT AND IS NEVER UPDATED. Comparing this quarter's
 * concentration to last quarter's requires last quarter's numbers to have
 * survived unchanged, which is why `tp_concentration_analyses` has no
 * `updated_at`.
 *
 * @phpstan-type ConcentrationCluster array{key: string, label: string, engagements: int, critical_functions: int, spend_minor: int, critical_engagements: int, engagement_references: non-empty-list<string>, indirect: bool}
 */
class ConcentrationAnalyzer
{
    public const DIMENSIONS = [
        'provider' => 'Direct provider',
        'provider_group' => 'Provider group, including sub-processors',
        'ultimate_parent' => 'Ultimate parent group',
        'country' => 'Country of processing',
        'cloud_region' => 'Cloud region',
    ];

    /**
     * TRD §7.8's band edges.
     *
     * The authoritative values are `tprm.scoring.concentration.hhi_bands`,
     * which Phase 0 declared and a tenant ruleset may override. These
     * constants are the fallback for a configuration that has lost them, and
     * `bandEdges()` is what anything reads.
     */
    public const BAND_DIVERSIFIED = 1500;

    public const BAND_CONCENTRATED = 2500;

    public function __construct(private readonly NthPartyGraph $graph) {}

    /**
     * Analyse one dimension.
     *
     * PURE: it computes and returns, and writes nothing. The snapshot is
     * `ConcentrationService`'s job. Having the calculation persist its own
     * result meant every screen render, every test assertion and every
     * what-if recalculation left a row in the run history — a history that
     * exists so a board can compare quarters, quietly filling with rows
     * nobody made a decision from.
     *
     * @return array<string, mixed>
     */
    public function analyse(int $organizationId, string $dimension = 'provider_group'): array
    {
        $engagements = Engagement::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['draft', 'terminated', 'archived'])
            ->with(['thirdParty:id,uuid,slug,legal_name,ultimate_parent_id', 'businessFunctions'])
            ->get();

        $clusters = $this->cluster($engagements, $dimension);
        $hhi = $this->hhi($clusters);
        $spofs = $this->singlePointsOfFailure($clusters, $engagements);

        $result = [
            'dimension' => $dimension,
            'dimension_label' => self::DIMENSIONS[$dimension] ?? $dimension,
            'clusters' => $clusters->values()->all(),
            'hhi' => $hhi,
            'band' => $this->band($hhi),
            'band_label' => $this->bandLabel($hhi),
            'spof' => $spofs,
            'engagements' => $engagements->count(),
            'critical_functions' => $clusters->sum('critical_functions'),
            'run_at' => now()->toDayDateTimeString(),
        ];

        $result['threshold_breaches'] = $this->breaches($result, $organizationId);

        return $result;
    }

    /**
     * Group engagements by the dimension and count what matters.
     *
     * @param  Collection<int, Engagement>  $engagements
     * @return Collection<int, ConcentrationCluster>
     */
    public function cluster(Collection $engagements, string $dimension)
    {
        $groups = [];

        foreach ($engagements as $engagement) {
            foreach ($this->keysFor($engagement, $dimension) as $key => $label) {
                $groups[$key] ??= [
                    'key' => $key,
                    'label' => $label,
                    'engagements' => 0,
                    'critical_functions' => 0,
                    'spend_minor' => 0,
                    'critical_engagements' => 0,
                    'engagement_references' => [],
                    // Whether this cluster is reached through a sub-processor
                    // rather than directly. Six banks using six different core
                    // vendors all hosting on one cloud is the case this flag
                    // exists to make visible.
                    'indirect' => false,
                ];

                $groups[$key]['engagements']++;
                $groups[$key]['critical_functions'] += $this->criticalFunctionCount($engagement);
                $groups[$key]['spend_minor'] += (int) ($engagement->annual_spend_minor ?? 0);
                $groups[$key]['engagement_references'][] = $engagement->reference;

                if (in_array($engagement->effective_tier?->value, ['critical', 'high'], true)) {
                    $groups[$key]['critical_engagements']++;
                }

                if ($engagement->third_party_id !== $this->directPartyFor($key)) {
                    $groups[$key]['indirect'] = true;
                }
            }
        }

        return collect($groups)
            ->sortByDesc('critical_functions')
            ->sortByDesc('engagements')
            ->values();
    }

    /**
     * HHI over critical-function dependency — TRD §7.8.
     *
     * Falls back to engagement count where no engagement supports a critical
     * function. That is not a substitute measure so much as an honest one: a
     * portfolio with no critical dependencies has no critical-function
     * concentration, and reporting an index of zero would read as
     * "diversified" when the truth is "not applicable". The count-based index
     * says something real about the portfolio's shape instead.
     *
     * @param  Collection<int, ConcentrationCluster>  $clusters
     */
    public function hhi(Collection $clusters): float
    {
        $total = $clusters->sum('critical_functions');
        $field = 'critical_functions';

        if ($total === 0) {
            $total = $clusters->sum('engagements');
            $field = 'engagements';
        }

        if ($total === 0) {
            return 0.0;
        }

        $index = 0.0;

        foreach ($clusters as $cluster) {
            $share = $cluster[$field] / $total;
            $index += pow($share * 100, 2);
        }

        return round($index, 2);
    }

    public function band(float $hhi): string
    {
        ['diversified' => $diversified, 'concentrated' => $concentrated] = $this->bandEdges();

        return match (true) {
            $hhi < $diversified => 'diversified',
            $hhi <= $concentrated => 'moderate',
            default => 'concentrated',
        };
    }

    /**
     * The band edges in force, from the Phase 0 configuration.
     *
     * @return array{diversified: float, concentrated: float}
     */
    public function bandEdges(): array
    {
        /** @var array<string, array{0: int, 1: int}> $bands */
        $bands = config('tprm.scoring.concentration.hhi_bands', []);

        return [
            'diversified' => (float) ($bands['moderate'][0] ?? self::BAND_DIVERSIFIED),
            'concentrated' => (float) ($bands['moderate'][1] ?? self::BAND_CONCENTRATED),
        ];
    }

    public function bandLabel(float $hhi): string
    {
        return match ($this->band($hhi)) {
            'diversified' => 'Diversified',
            'moderate' => 'Moderately concentrated',
            default => 'Concentrated',
        };
    }

    /**
     * The single points of failure table — DORA Art. 29, BCBS P3.
     *
     * "The strongest board-pack slide in the product", and what makes it
     * strong is the last three columns: substitutability, time to replace, and
     * whether the exit plan has ever been TESTED. A concentration number tells
     * a board there is a dependency; those three tell them whether they could
     * do anything about it.
     *
     * @param  Collection<int, ConcentrationCluster>  $clusters
     * @param  Collection<int, Engagement>  $engagements
     * @return list<array<string, mixed>>
     */
    public function singlePointsOfFailure(Collection $clusters, Collection $engagements): array
    {
        return $clusters
            ->filter(fn (array $cluster) => $cluster['critical_functions'] > 0 || $cluster['critical_engagements'] > 0)
            ->map(function (array $cluster) use ($engagements) {
                $members = $engagements->filter(
                    fn (Engagement $engagement) => in_array($engagement->reference, $cluster['engagement_references'], true)
                );

                $worstSubstitutability = $members
                    ->pluck('substitutability')
                    ->filter()
                    ->sortBy(fn (?string $value) => match ($value) {
                        'none' => 0, 'difficult' => 1, 'moderate' => 2, default => 3,
                    })
                    ->first();

                $longestReplacement = $members->max('time_to_replace_months');
                $exitPlans = $this->exitPlanState($members);

                return [
                    'label' => $cluster['label'],
                    'engagements' => $cluster['engagements'],
                    'critical_functions' => $cluster['critical_functions'],
                    'critical_engagements' => $cluster['critical_engagements'],
                    'spend_minor' => $cluster['spend_minor'],
                    'indirect' => $cluster['indirect'],
                    'substitutability' => $worstSubstitutability,
                    'time_to_replace_months' => $longestReplacement,
                    'exit_plan' => $exitPlans,
                    'references' => $cluster['engagement_references'],
                    // The sentence that belongs on the slide. A number with no
                    // reading is a number a board asks the same question about
                    // every quarter.
                    'note' => $this->spofNote($cluster, $worstSubstitutability, $longestReplacement, $exitPlans),
                ];
            })
            ->sortByDesc('critical_functions')
            ->values()
            ->all();
    }

    /**
     * Threshold breaches — FR-NTH-05.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function breaches(array $result, int $organizationId): array
    {
        /** @var array<string, mixed> $thresholds */
        $thresholds = config('tprm.scoring.concentration', []);

        $breaches = [];

        $maxCriticalFunctions = (int) ($thresholds['max_critical_functions_per_group'] ?? 3);

        foreach ($result['clusters'] as $cluster) {
            if ($cluster['critical_functions'] > $maxCriticalFunctions) {
                $breaches[] = [
                    'threshold' => 'max_critical_functions_per_group',
                    'cluster' => $cluster['label'],
                    'value' => $cluster['critical_functions'],
                    'limit' => $maxCriticalFunctions,
                    'message' => sprintf(
                        '%d critical business functions depend on %s, against a limit of %d.',
                        $cluster['critical_functions'],
                        $cluster['label'],
                        $maxCriticalFunctions,
                    ),
                ];
            }
        }

        $spendShare = (float) ($thresholds['max_spend_share_per_group'] ?? 0.35);
        $totalSpend = collect($result['clusters'])->sum('spend_minor');

        if ($totalSpend > 0) {
            foreach ($result['clusters'] as $cluster) {
                $share = $cluster['spend_minor'] / $totalSpend;

                if ($share > $spendShare) {
                    $breaches[] = [
                        'threshold' => 'max_spend_share_per_group',
                        'cluster' => $cluster['label'],
                        'value' => round($share * 100, 1),
                        'limit' => round($spendShare * 100, 1),
                        'message' => sprintf(
                            '%s%% of third-party spend sits with %s, against a limit of %s%%.',
                            round($share * 100, 1),
                            $cluster['label'],
                            round($spendShare * 100, 1),
                        ),
                    ];
                }
            }
        }

        $concentratedAt = $this->bandEdges()['concentrated'];

        if ($result['hhi'] > $concentratedAt) {
            $breaches[] = [
                'threshold' => 'hhi',
                'cluster' => null,
                'value' => $result['hhi'],
                'limit' => $concentratedAt,
                'message' => sprintf(
                    'The concentration index for %s is %s, above the %s that marks a concentrated portfolio.',
                    $result['dimension_label'],
                    $result['hhi'],
                    $concentratedAt,
                ),
            ];
        }

        unset($organizationId);

        return $breaches;
    }

    /* ------------------------------------------------------------------ */

    /**
     * The cluster keys an engagement belongs to, for a dimension.
     *
     * `provider_group` returns MORE THAN ONE key — the direct provider and
     * every confirmed sub-processor beneath it — which is what makes the
     * fourth-party concentration visible. An engagement whose core banking
     * vendor hosts on a cloud provider counts towards both.
     *
     * @return array<string, string>
     */
    private function keysFor(Engagement $engagement, string $dimension): array
    {
        $party = $engagement->thirdParty;

        if ($party === null) {
            return [];
        }

        return match ($dimension) {
            'provider' => ['tp'.$party->getKey() => $party->legal_name],

            'provider_group' => $this->providerGroupKeys($party),

            'ultimate_parent' => (function () use ($party) {
                $parentId = $party->ultimate_parent_id;

                if ($parentId === null) {
                    return ['tp'.$party->getKey() => $party->legal_name];
                }

                $parent = ThirdParty::query()->find($parentId);

                return ['tp'.$parentId => ($parent->legal_name ?? $party->legal_name).' (group)'];
            })(),

            'country' => $engagement->data_location_processing === null
                ? []
                : ['c'.$engagement->data_location_processing => $engagement->data_location_processing],

            'cloud_region' => $engagement->cloud_model === null
                ? []
                : ['cloud:'.$engagement->cloud_model => ucfirst((string) $engagement->cloud_model)],

            default => [],
        };
    }

    /**
     * The provider and everything it depends on, to the graph's default depth.
     *
     * @return array<string, string>
     */
    private function providerGroupKeys(ThirdParty $party): array
    {
        $keys = ['tp'.$party->getKey() => $party->legal_name];

        // Confirmed edges only. A proposal is a machine's reading of a
        // document, and the concentration index is a figure a board acts on.
        $graph = $this->graph->descendants([$party->getKey()], $this->graph->defaultDepth(), confirmedOnly: true);

        foreach ($graph['nodes'] as $node) {
            if (($node['depth'] ?? 0) === 0) {
                continue;
            }

            // Unmatched names cluster by name. A sub-processor nobody has
            // onboarded is still a shared dependency if three vendors all name
            // it, and keying on the raw name is what lets that show.
            $key = $node['third_party_id'] === null
                ? 'raw:'.strtolower(trim((string) $node['name']))
                : 'tp'.$node['third_party_id'];

            $keys[$key] = $node['name'];
        }

        return $keys;
    }

    private function directPartyFor(string $key): ?int
    {
        return str_starts_with($key, 'tp') ? (int) substr($key, 2) : null;
    }

    private function criticalFunctionCount(Engagement $engagement): int
    {
        return $engagement->businessFunctions
            ->filter(fn ($function) => in_array($function->criticality, ['critical', 'important'], true))
            ->count();
    }

    /**
     * @param  Collection<int, Engagement>  $members
     * @return array<string, mixed>
     */
    private function exitPlanState(Collection $members): array
    {
        $plans = ExitPlan::query()
            ->whereIn('engagement_id', $members->pluck('id'))
            ->get();

        return [
            'engagements_with_plan' => $plans->pluck('engagement_id')->unique()->count(),
            'engagements' => $members->count(),
            // Whether it has ever been TESTED, which is the column that
            // separates an exit plan from a document about exiting.
            'tested' => $plans->filter(fn (ExitPlan $plan) => $plan->last_tested_at !== null)->count(),
            'never_tested' => $plans->filter(fn (ExitPlan $plan) => $plan->last_tested_at === null)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $cluster
     * @param  array<string, mixed>  $exitPlans
     */
    private function spofNote(array $cluster, ?string $substitutability, $months, array $exitPlans): string
    {
        $parts = [sprintf(
            '%d critical function(s) across %d engagement(s) depend on %s.',
            $cluster['critical_functions'],
            $cluster['engagements'],
            $cluster['label'],
        )];

        if ($cluster['indirect']) {
            $parts[] = 'Some of that dependency is indirect, through a sub-processor rather than a contract '
                .'we hold.';
        }

        if ($substitutability === 'none') {
            $parts[] = 'At least one of them has no substitute.';
        } elseif ($months !== null) {
            $parts[] = sprintf('Replacement is estimated at %d months.', (int) $months);
        }

        if ($exitPlans['engagements_with_plan'] < $exitPlans['engagements']) {
            $parts[] = sprintf(
                '%d of %d have no exit plan.',
                $exitPlans['engagements'] - $exitPlans['engagements_with_plan'],
                $exitPlans['engagements'],
            );
        } elseif ($exitPlans['never_tested'] > 0) {
            $parts[] = sprintf(
                '%d exit plan(s) have never been tested, so the replacement estimate is an assertion.',
                $exitPlans['never_tested'],
            );
        }

        return implode(' ', $parts);
    }
}
