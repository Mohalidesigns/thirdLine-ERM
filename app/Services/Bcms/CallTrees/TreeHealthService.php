<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeStatus;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\Contact;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Whether the cascades would work if they were needed today.
 *
 * THE DASHBOARD EXISTS BECAUSE A CALL TREE FAILS QUIETLY. Nothing goes red when
 * a unit lead resigns; the tree keeps looking exactly as complete as it did the
 * day it was approved, and the failure is discovered during the emergency. Every
 * figure here is a way of making that visible before the emergency: stale trees,
 * orphaned nodes, must-reach nodes with no deputy, and contacts whose numbers
 * have not been checked.
 *
 * FOUR KRIs, AND EACH ONE HAS AN OWNER WHO CAN MOVE IT. "Call tree data
 * confidence" is fixed by verifying contacts; "trees overdue for review" by the
 * department heads; "cascade completion rate" by running and repairing tests;
 * "must-reach nodes without a deputy" by ten minutes in the designer. A blended
 * resilience index would have no owner and would move for reasons nobody could
 * name (development standard §5).
 *
 * THE DATA-CONFIDENCE FIGURE IS COMPUTED HERE AND PHASE 2C WILL OWN IT. The
 * quarterly verification campaign that moves it is 2C's, and when that lands
 * this method should read its result rather than keep its own count. Until
 * then the figure is real — it counts contacts with a verification date inside
 * the window — and is not a placeholder.
 */
class TreeHealthService
{
    /** A contact verified longer ago than this is no longer evidence of anything. */
    private const VERIFICATION_WINDOW_DAYS = 90;

    public function __construct(private CallTreeService $trees) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?User $user = null): array
    {
        /** @var Collection<int, CallTree> $trees */
        $trees = $this->trees->currentQuery($user)
            ->with(['businessUnit:id,name', 'site:id,name', 'approver:id,name'])
            ->withCount('nodes')
            ->orderBy('name')
            ->get();

        $rows = [];
        $orphanTotal = 0;
        $missingDeputyTotal = 0;

        foreach ($trees as $tree) {
            $structure = $this->trees->structure($tree);
            $stale = $this->trees->isStale($tree);

            $orphanTotal += $structure['orphans'];
            $missingDeputyTotal += $structure['missing_deputies'];

            $rows[] = [
                'id' => (int) $tree->getKey(),
                'uuid' => $tree->uuid,
                'name' => $tree->name,
                'type' => $tree->tree_type->value,
                'type_label' => $tree->tree_type->label(),
                'status' => $tree->status->value,
                'status_label' => $tree->status->label(),
                'version' => $tree->version,
                'business_unit' => $tree->businessUnit?->name,
                'site' => $tree->site?->name,
                'nodes' => $structure['total'],
                'tiers' => $this->tierDepth($structure['nodes']),
                'orphans' => $structure['orphans'],
                'missing_deputies' => $structure['missing_deputies'],
                'source' => $tree->source->value,
                'source_label' => $tree->source->label(),
                // A generated tree nobody has edited has been checked by
                // nobody, however recent its review date says it is.
                'never_human_checked' => $tree->source === CallTreeSource::AdGenerated,
                'approved_by' => $tree->approver?->name,
                'last_reviewed_at' => ($tree->last_reviewed_at ?? $tree->approved_at)?->toDateString(),
                'review_frequency_days' => (int) $tree->review_frequency_days,
                'is_stale' => $stale,
                'days_overdue' => $this->trees->daysOverdue($tree),
                'last_test' => $this->lastTestOf($tree),
            ];
        }

        return [
            'trees' => $rows,
            'summary' => [
                'total' => count($rows),
                'approved' => count(array_filter($rows, fn ($r) => $r['status'] === CallTreeStatus::Approved->value)),
                'draft' => count(array_filter($rows, fn ($r) => $r['status'] === CallTreeStatus::Draft->value)),
                'stale' => count(array_filter($rows, fn ($r) => $r['is_stale'])),
                'orphaned_nodes' => $orphanTotal,
                'missing_deputies' => $missingDeputyTotal,
                'never_tested' => count(array_filter($rows, fn ($r) => $r['last_test'] === null)),
            ],
            'kris' => $this->kris($user),
            'data_confidence' => $this->dataConfidence(),
            'recent_tests' => $this->recentTests($user),
        ];
    }

    /**
     * The contact-data confidence figure — Blueprint §6.4's KRI.
     *
     * VERIFIED MEANS VERIFIED RECENTLY. A number confirmed in 2023 is not
     * evidence that it works today, and counting it would make the figure drift
     * upwards for ever while the roster decayed. The window is ninety days
     * because the verification campaign is quarterly.
     *
     * @return array<string, mixed>
     */
    public function dataConfidence(): array
    {
        $base = Contact::query()->where('is_active', true);
        $total = (clone $base)->count();

        if ($total === 0) {
            // No roster is not 0% confidence, it is no figure at all. A red
            // zero on an empty tenant is a bug report waiting to be filed.
            return [
                'contacts' => 0, 'verified' => 0, 'confidence' => null,
                'unverified' => 0, 'failing' => 0, 'consent_withdrawn' => 0,
                'window_days' => self::VERIFICATION_WINDOW_DAYS,
                'note' => 'No active contacts. The roster is Phase 2C\'s to populate from the directory.',
            ];
        }

        $cutoff = Carbon::now()->subDays(self::VERIFICATION_WINDOW_DAYS);
        $verified = (clone $base)->where('last_verified_at', '>=', $cutoff)->count();

        return [
            'contacts' => $total,
            'verified' => $verified,
            'confidence' => round($verified / $total * 100, 1),
            'unverified' => $total - $verified,
            'failing' => (clone $base)->where('consecutive_failures', '>=', 3)->count(),
            'consent_withdrawn' => (clone $base)->where('consent_status', 'withdrawn')->count(),
            'window_days' => self::VERIFICATION_WINDOW_DAYS,
            'note' => null,
        ];
    }

    /**
     * The four cascade KRIs, with their current values.
     *
     * @return list<array<string, mixed>>
     */
    public function kris(?User $user = null): array
    {
        $trees = $this->trees->currentQuery($user)->get();
        $stale = $this->trees->staleQuery($user)->count();
        $confidence = $this->dataConfidence();

        $latest = CallTreeTest::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', Carbon::now()->subYear())
            ->get();

        $rates = $latest->map(fn (CallTreeTest $t) => $t->completion_rate === null ? null : (float) $t->completion_rate)
            ->filter(fn ($v) => $v !== null)->values();

        $missingDeputies = 0;
        foreach ($trees as $tree) {
            $missingDeputies += $this->trees->structure($tree)['missing_deputies'];
        }

        return [
            [
                'code' => 'BCMS-CT-CONFIDENCE',
                'name' => 'Call tree data confidence',
                'unit' => '%',
                'direction' => 'lower_is_worse',
                'value' => $confidence['confidence'],
                'basis' => $confidence['confidence'] === null
                    ? 'No active contacts to measure.'
                    : $confidence['verified'].' of '.$confidence['contacts'].' contacts verified in the last '
                        .self::VERIFICATION_WINDOW_DAYS.' days.',
            ],
            [
                'code' => 'BCMS-CT-STALE',
                'name' => 'Call trees overdue for review',
                'unit' => 'trees',
                'direction' => 'higher_is_worse',
                'value' => $stale,
                'basis' => $stale.' of '.$trees->count().' current trees are past their review cadence.',
            ],
            [
                'code' => 'BCMS-CT-COMPLETION',
                'name' => 'Cascade completion rate',
                'unit' => '%',
                'direction' => 'lower_is_worse',
                // Null, not zero, where no test has been run. Zero would read
                // as "every cascade failed" (development standard §5).
                'value' => $rates->isEmpty() ? null : round($rates->avg(), 1),
                'basis' => $rates->isEmpty()
                    ? 'No cascade has been completed in the last twelve months.'
                    : 'Mean of '.$rates->count().' completed tests in the last twelve months.',
            ],
            [
                'code' => 'BCMS-CT-DEPUTY-GAP',
                'name' => 'Must-reach nodes with no deputy',
                'unit' => 'nodes',
                'direction' => 'higher_is_worse',
                'value' => $missingDeputies,
                'basis' => $missingDeputies.' nodes marked must-reach have nobody to escalate to.',
            ],
        ];
    }

    /**
     * Mirror today's readings into the platform KRI register.
     *
     * ONE-WAY AND TOLERANT, exactly like `ErmBridge`. A deployment whose KRI
     * register is not configured must still be able to run a cascade; BCMS
     * working without the KRI link is a far smaller problem than BCMS refusing
     * to record a test because a measurement could not be filed. Nothing is
     * created here — a KRI the risk team has not defined is not one this module
     * invents on their behalf.
     *
     * @return list<string> the codes that were written
     */
    public function mirrorKris(?User $user = null): array
    {
        $written = [];

        foreach ($this->kris($user) as $kri) {
            if ($kri['value'] === null) {
                continue;
            }

            try {
                $target = KeyRiskIndicator::query()->where('kri_code', $kri['code'])->first();

                if ($target === null) {
                    continue;
                }

                KriMeasurement::query()->updateOrCreate(
                    ['kri_id' => $target->getKey(), 'measurement_date' => Carbon::now()->toDateString()],
                    ['value' => $kri['value'], 'data_source' => 'bcms.call_trees', 'notes' => $kri['basis']],
                );

                $written[] = $kri['code'];
            } catch (Throwable) {
                // Deliberately swallowed, per the ErmBridge precedent above.
                continue;
            }
        }

        return $written;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function tierDepth(array $nodes, int $depth = 0): int
    {
        $max = $nodes === [] ? $depth : $depth + 1;

        foreach ($nodes as $node) {
            $max = max($max, $this->tierDepth($node['children'], $depth + 1));
        }

        return $max;
    }

    /** @return array<string, mixed>|null */
    private function lastTestOf(CallTree $tree): ?array
    {
        $test = CallTreeTest::query()
            ->where('call_tree_id', $tree->getKey())
            ->whereNotNull('initiated_at')
            ->orderByDesc('initiated_at')
            ->first();

        if ($test === null) {
            return null;
        }

        return [
            'uuid' => $test->uuid,
            'initiated_at' => $test->initiated_at?->toDateString(),
            'mode' => $test->mode->value,
            'completion_rate' => $test->completion_rate === null ? null : (float) $test->completion_rate,
            'completed' => $test->completed_at !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentTests(?User $user = null): array
    {
        $visible = $this->trees->currentQuery($user)->pluck('id');

        return CallTreeTest::query()
            ->whereIn('call_tree_id', $visible)
            ->whereNotNull('initiated_at')
            ->with('callTree:id,uuid,name')
            ->orderByDesc('initiated_at')
            ->limit(10)
            ->get()
            ->map(fn (CallTreeTest $t) => [
                'uuid' => $t->uuid,
                'tree' => $t->callTree?->name,
                'tree_uuid' => $t->callTree?->uuid,
                'mode' => $t->mode->value,
                'announced' => (bool) $t->announced,
                'initiated_at' => $t->initiated_at?->toIso8601String(),
                'completed' => $t->completed_at !== null,
                'completion_rate' => $t->completion_rate === null ? null : (float) $t->completion_rate,
                'blocked' => (int) ($t->scorecard['blocked_downstream'] ?? 0),
            ])
            ->all();
    }
}
