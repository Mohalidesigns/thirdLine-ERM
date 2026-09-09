<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\User;
use App\Services\Bcms\CallTrees\BrokenBranchAnalyser;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\CascadeScorecard;
use App\Services\Bcms\Notification\ChannelRegistry;

/**
 * Everything the three call-tree screens draw, assembled on the server.
 *
 * Development standard §1: the controller decides who may look, the presenter
 * decides what they see. The tree is a recursive structure and a React
 * component that walked it would have to know about tiers, deputies, blocked
 * branches and consent exclusions — four rules that already exist in PHP and
 * would then exist twice.
 *
 * THE MOCK WARNING TRAVELS WITH THE PAYLOAD. Every channel is still a Phase 0
 * recording mock, and a screen that shows a green "reached" without saying so
 * would be teaching somebody to trust a cascade that dispatched nothing. It is
 * the same reason `ChannelRegistry::isMock()` exists.
 */
class CallTreePresenter
{
    public function __construct(
        private CallTreeService $trees,
        private CascadeScorecard $scorecard,
        private BrokenBranchAnalyser $branches,
        private ChannelRegistry $channels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function designer(CallTree $tree, ?User $user = null): array
    {
        $structure = $this->trees->structure($tree);

        return [
            'tree' => $this->summary($tree, $structure),
            'nodes' => $structure['nodes'],
            'orphaned' => $this->trees->orphanedNodes($tree),
            'versions' => $this->versions($tree),
            'tests' => CallTreeTest::query()
                ->where('call_tree_id', $tree->getKey())
                ->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (CallTreeTest $t) => [
                    'uuid' => $t->uuid,
                    'mode' => $t->mode->value,
                    'mode_label' => $t->mode->label(),
                    'announced' => (bool) $t->announced,
                    'initiated_at' => $t->initiated_at?->toIso8601String(),
                    'completed_at' => $t->completed_at?->toIso8601String(),
                    'completion_rate' => $t->completion_rate === null ? null : (float) $t->completion_rate,
                    'blocked' => (int) ($t->scorecard['blocked_downstream'] ?? 0),
                ])->all(),
            'modes' => array_map(
                fn (CascadeMode $m) => [
                    'value' => $m->value,
                    'label' => $m->label(),
                    'automated_from_tier' => $m->automatedFromTier(),
                ],
                CascadeMode::cases(),
            ),
            'channels' => array_map(
                fn (ChannelKey $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'is_mock' => $this->channels->isMock($c),
                ],
                ChannelKey::cases(),
            ),
            'tier_labels' => $this->tierLabels($tree),
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private function summary(CallTree $tree, array $structure): array
    {
        return [
            'id' => (int) $tree->getKey(),
            'uuid' => $tree->uuid,
            'name' => $tree->name,
            'type' => $tree->tree_type->value,
            'type_label' => $tree->tree_type->label(),
            'status' => $tree->status->value,
            'status_label' => $tree->status->label(),
            'editable' => $tree->status->isEditable(),
            'testable' => $tree->status->isCascadable(),
            'version' => $tree->version,
            'source' => $tree->source->value,
            'source_label' => $tree->source->label(),
            'business_unit' => $tree->businessUnit?->name,
            'site' => $tree->site?->name,
            'approved_at' => $tree->approved_at?->toDateString(),
            'approved_by' => $tree->approver?->name,
            'last_reviewed_at' => ($tree->last_reviewed_at ?? $tree->approved_at)?->toDateString(),
            'review_frequency_days' => (int) $tree->review_frequency_days,
            'is_stale' => $this->trees->isStale($tree),
            'days_overdue' => $this->trees->daysOverdue($tree),
            'iso_clause_ref' => $tree->iso_clause_ref,
            'node_count' => $structure['total'],
            'orphan_count' => $structure['orphans'],
            'missing_deputy_count' => $structure['missing_deputies'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(CallTree $tree): array
    {
        return $this->trees->versions($tree)->map(fn (CallTree $v) => [
            'uuid' => $v->uuid,
            'version' => $v->version,
            'status' => $v->status->value,
            'status_label' => $v->status->label(),
            'is_current' => $v->status !== CallTreeStatus::Archived,
            'approved_at' => $v->approved_at?->toDateString(),
            'approved_by' => $v->approver?->name,
            'nodes' => $v->nodes()->count(),
        ])->all();
    }

    /**
     * The live cascade map. Small on purpose — it is polled.
     *
     * @return array<string, mixed>
     */
    public function live(CallTreeTest $test): array
    {
        $analysis = $this->branches->analyse($test);
        $rows = CallTreeTestNode::query()->where('test_id', $test->getKey())->get();

        $counts = [];
        foreach ($rows as $row) {
            $outcome = $row->outcome ?? CascadeOutcome::Pending;
            $state = match (true) {
                $outcome === CascadeOutcome::Pending => $row->contacted_at === null ? 'waiting' : 'pending',
                $outcome === CascadeOutcome::Blocked => 'blocked',
                $outcome === CascadeOutcome::ConsentBlocked => 'excluded',
                $outcome->isReached() => 'reached',
                default => 'failed',
            };
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        return [
            'test' => $this->testSummary($test),
            'tree' => $analysis['tree'],
            'counts' => $counts,
            'total_blocked' => $analysis['total_blocked'],
            'tiers' => $this->tierProgress($rows),
            'running' => $test->initiated_at !== null && $test->completed_at === null,
            // Same warning as the EMNS console will carry. A green tick from a
            // recording mock is the most dangerous thing this module can show.
            'channels_are_mocked' => $this->anyMock(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function results(CallTreeTest $test): array
    {
        $analysis = $this->branches->analyse($test);

        return [
            'test' => $this->testSummary($test),
            'scorecard' => $test->completed_at !== null && $test->scorecard !== []
                ? $test->scorecard
                : $this->scorecard->compute($test),
            'scorecard_is_stored' => $test->completed_at !== null && $test->scorecard !== [],
            'branches' => $analysis['branches'],
            'total_blocked' => $analysis['total_blocked'],
            'tree' => $analysis['tree'],
            'outcomes' => array_map(
                fn (CascadeOutcome $o) => ['value' => $o->value, 'label' => $o->label()],
                array_values(array_filter(
                    CascadeOutcome::cases(),
                    fn (CascadeOutcome $o) => ! $o->isReached() && $o !== CascadeOutcome::Pending,
                )),
            ),
            'channels_are_mocked' => $this->anyMock(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testSummary(CallTreeTest $test): array
    {
        $tree = $test->callTree;

        return [
            'uuid' => $test->uuid,
            'tree_name' => $tree?->name,
            'tree_uuid' => $tree?->uuid,
            'tree_version' => $tree?->version,
            'mode' => $test->mode->value,
            'mode_label' => $test->mode->label(),
            'automated_from_tier' => $test->mode->automatedFromTier(),
            'announced' => (bool) $test->announced,
            'initiated_at' => $test->initiated_at?->toIso8601String(),
            'completed_at' => $test->completed_at?->toIso8601String(),
            'total_cascade_minutes' => $test->total_cascade_minutes === null ? null : (int) $test->total_cascade_minutes,
            'nodes_total' => (int) $test->nodes_total,
            'nodes_reached' => (int) $test->nodes_reached,
            'completion_rate' => $test->completion_rate === null ? null : (float) $test->completion_rate,
            'iso_clause_ref' => $test->iso_clause_ref,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CallTreeTestNode>  $rows
     * @return list<array<string, mixed>>
     */
    private function tierProgress($rows): array
    {
        $out = [];

        foreach ($rows->groupBy(fn (CallTreeTestNode $r) => (int) ($r->tier_snapshot ?? 0))->sortKeys() as $tier => $group) {
            $reached = $group->filter(fn (CallTreeTestNode $r) => $r->outcome?->isReached() === true)->count();
            $settled = $group->filter(fn (CallTreeTestNode $r) => $r->outcome?->isSettled() === true)->count();

            $out[] = [
                'tier' => (int) $tier,
                'total' => $group->count(),
                'reached' => $reached,
                'settled' => $settled,
                'percent' => $group->count() === 0 ? null : round($reached / $group->count() * 100),
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    private function tierLabels(CallTree $tree): array
    {
        return [
            0 => $tree->tree_type->tierZeroLabel(),
            1 => 'Crisis management team / heads',
            2 => 'Unit leads, branch managers, wardens',
            3 => 'All staff',
        ];
    }

    private function anyMock(): bool
    {
        foreach (ChannelKey::cases() as $channel) {
            if ($this->channels->isMock($channel)) {
                return true;
            }
        }

        return false;
    }
}
