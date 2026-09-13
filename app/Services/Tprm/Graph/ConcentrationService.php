<?php

namespace App\Services\Tprm\Graph;

use App\Events\Tprm\ConcentrationThresholdBreached;
use App\Models\Tprm\ConcentrationAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Runs the concentration analysis, snapshots it and alerts on new breaches —
 * FR-NTH-03 through FR-NTH-05.
 *
 * THE SNAPSHOT IS THE PRODUCT, NOT THE NUMBER. A live-computed HHI on a screen
 * answers "how concentrated are we"; a board asking a supervisor's question —
 * "you told us in March you were reducing this" — needs March's figure to
 * still exist, computed by March's rules over March's portfolio. That is why
 * `ConcentrationAnalysis` refuses updates and why every run writes a row even
 * when nothing changed.
 *
 * Runs are per DIMENSION. Provider group and country are different questions
 * with different answers, and averaging them would produce a number that is
 * true of nothing.
 */
class ConcentrationService
{
    public function __construct(private readonly ConcentrationAnalyzer $analyzer) {}

    /**
     * Run one dimension and store the snapshot.
     */
    public function run(int $organizationId, string $dimension = 'provider_group', ?Carbon $asOf = null): ConcentrationAnalysis
    {
        $result = $this->analyzer->analyse($organizationId, $dimension);
        /** @var list<array<string, mixed>> $breaches */
        $breaches = $result['threshold_breaches'];

        $previous = $this->latest($organizationId, $dimension);

        $analysis = ConcentrationAnalysis::create([
            'organization_id' => $organizationId,
            'run_at' => $asOf ?? now(),
            'dimension' => $dimension,
            'results' => $result,
            'hhi' => $result['hhi'],
            'spof_list' => $result['spof'],
            'threshold_breaches' => $breaches,
            'created_at' => now(),
        ]);

        $delta = $this->delta($previous, $breaches);

        if ($delta['new'] !== [] || $delta['resolved'] !== []) {
            ConcentrationThresholdBreached::dispatch($analysis, $delta['new'], $delta['resolved']);
        }

        return $analysis;
    }

    /**
     * Run every dimension — the quarterly job.
     *
     * @return Collection<int, ConcentrationAnalysis>
     */
    public function runAll(int $organizationId, ?Carbon $asOf = null): Collection
    {
        return collect(array_keys(ConcentrationAnalyzer::DIMENSIONS))
            ->map(fn (string $dimension): ConcentrationAnalysis => $this->run($organizationId, $dimension, $asOf))
            ->values();
    }

    public function latest(int $organizationId, string $dimension = 'provider_group'): ?ConcentrationAnalysis
    {
        return ConcentrationAnalysis::query()
            ->where('organization_id', $organizationId)
            ->where('dimension', $dimension)
            ->orderByDesc('run_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What is newly breached and what has cleared since the previous run.
     *
     * Identity is (threshold, cluster). A breach whose VALUE moved — five
     * critical functions on one provider becoming six — is the same breach,
     * and re-alerting on it would undo the point of comparing at all. The
     * screen shows the current value; the alert is about the state changing.
     *
     * @param  list<array<string, mixed>>  $current
     * @return array{new: list<array<string, mixed>>, resolved: list<array<string, mixed>>}
     */
    public function delta(?ConcentrationAnalysis $previous, array $current): array
    {
        /** @var list<array<string, mixed>> $before */
        $before = $previous->threshold_breaches ?? [];

        $key = static fn (array $b): string => ($b['threshold'] ?? '').'|'.($b['cluster'] ?? '');

        $beforeKeys = array_map($key, $before);
        $currentKeys = array_map($key, $current);

        return [
            'new' => array_values(array_filter(
                $current,
                static fn (array $b): bool => ! in_array($key($b), $beforeKeys, true),
            )),
            'resolved' => array_values(array_filter(
                $before,
                static fn (array $b): bool => ! in_array($key($b), $currentKeys, true),
            )),
        ];
    }

    /**
     * The two runs a trend needs.
     *
     * @return array{current: ConcentrationAnalysis|null, previous: ConcentrationAnalysis|null, movement: float|null}
     */
    public function trend(int $organizationId, string $dimension = 'provider_group'): array
    {
        $runs = ConcentrationAnalysis::query()
            ->where('organization_id', $organizationId)
            ->where('dimension', $dimension)
            ->orderByDesc('run_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        $current = $runs->first();
        $previous = $runs->skip(1)->first();

        return [
            'current' => $current,
            'previous' => $previous,
            'movement' => $current !== null && $previous !== null
                ? round((float) $current->hhi - (float) $previous->hhi, 2)
                : null,
        ];
    }
}
