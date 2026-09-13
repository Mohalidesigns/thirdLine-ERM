<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\CascadeOutcome;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use Illuminate\Support\Collection;

/**
 * "Ibrahim Sani unreachable → 34 staff isolated."
 *
 * THE ONE SENTENCE THIS WHOLE PHASE EXISTS TO PRODUCE. Every other number on
 * the scorecard is a percentage a bank already has in a spreadsheet somewhere.
 * This one is the thing nobody can compute by hand: not that a call was missed,
 * but that missing it left thirty-four people uninformed — and precisely which
 * thirty-four, so the person who has to fix it knows what they are fixing.
 *
 * THE COUNT IS OF PEOPLE ACTUALLY BLOCKED, NOT OF DESCENDANTS. A node with
 * forty people below it, six of whom happened to acknowledge through the web
 * link anyway, blocked thirty-four. Reporting forty would be a number the
 * cascade record itself contradicts, and the first person to notice would stop
 * believing the other seven metrics too.
 *
 * ONLY THE HIGHEST FAILURE IN A BRANCH IS REPORTED AS BREAKING IT. When a unit
 * lead fails and their team lead below them was therefore never called, both
 * nodes failed but only the first one caused anything. Listing both would
 * double-count the same thirty-four people and turn one fixable problem into
 * two arguments about who owns it.
 */
class BrokenBranchAnalyser
{
    /**
     * @return array{branches: list<array<string, mixed>>, total_blocked: int, tree: list<array<string, mixed>>}
     */
    public function analyse(CallTreeTest $test): array
    {
        /** @var Collection<int, CallTreeTestNode> $rows */
        $rows = CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->with(['node:id,parent_node_id,tier,is_must_reach,deputy_contact_id,contact_id,role_label',
                'node.contact:id,full_name,mobile_primary,email,is_active,consent_status'])
            ->orderBy('tier_snapshot')->orderBy('id')
            ->get();

        $byNodeId = $rows->keyBy(fn (CallTreeTestNode $r) => (int) $r->node_id);

        $children = [];
        foreach ($rows as $row) {
            // A snapshot row whose node was deleted since the test has no
            // parent to hang from, and belongs at the top rather than nowhere:
            // it is still evidence that somebody was on the tree that day.
            $node = $row->node;
            $children[$node === null || $node->parent_node_id === null ? 0 : (int) $node->parent_node_id][] = $row;
        }

        // Everybody under a node, however deep, with a cycle guard: the parent
        // links come from user-editable data and an unguarded walk is an
        // out-of-memory error rather than an error message.
        $descendantsOf = function (int $nodeId) use (&$children): array {
            $out = [];
            $stack = [[$nodeId, 0]];
            $seen = [$nodeId => true];

            while ($stack !== []) {
                [$current, $depth] = array_pop($stack);

                if ($depth > 12) {
                    continue;
                }

                foreach ($children[$current] ?? [] as $child) {
                    $childId = (int) $child->node_id;

                    if (isset($seen[$childId])) {
                        continue;
                    }

                    $seen[$childId] = true;
                    $out[] = $child;
                    $stack[] = [$childId, $depth + 1];
                }
            }

            return $out;
        };

        $failedAbove = function (CallTreeTestNode $row) use ($byNodeId): bool {
            $cursor = $row->node?->parent_node_id;
            $depth = 0;

            while ($cursor !== null && $depth++ < 12) {
                $parent = $byNodeId->get((int) $cursor);

                if ($parent === null) {
                    return false;
                }

                if ($parent->outcome !== null && $parent->outcome->blocksDownstream()) {
                    return true;
                }

                $cursor = $parent->node?->parent_node_id;
            }

            return false;
        };

        $branches = [];
        $blockedIds = [];

        foreach ($rows as $row) {
            if ($row->outcome === null || ! $row->outcome->blocksDownstream()) {
                continue;
            }

            // Somebody above them already broke this branch; this node is a
            // consequence, not a cause.
            if ($failedAbove($row)) {
                continue;
            }

            $descendants = $descendantsOf((int) $row->node_id);

            $isolated = array_values(array_filter(
                $descendants,
                fn (CallTreeTestNode $d) => $d->outcome?->isReached() !== true,
            ));

            foreach ($isolated as $node) {
                $blockedIds[(int) $node->getKey()] = true;
            }

            $contact = $row->node?->contact;

            $branches[] = [
                'test_node_id' => (int) $row->getKey(),
                'node_id' => $row->node_id === null ? null : (int) $row->node_id,
                'name' => $row->contact_name_snapshot,
                'role_label' => $row->role_label_snapshot,
                'tier' => (int) ($row->tier_snapshot ?? 0),
                'outcome' => $row->outcome->value,
                'outcome_label' => $row->outcome->label(),
                'attempts' => (int) $row->attempts,
                'channel_used' => $row->channel_used,
                'notes' => $row->notes,
                'is_must_reach' => $row->node !== null && (bool) $row->node->is_must_reach,
                'has_deputy' => $row->node !== null && $row->node->deputy_contact_id !== null,
                'contact_id' => $contact?->getKey(),
                'contact_mobile' => $contact?->mobile_primary,
                'contact_email' => $contact?->email,
                'downstream_blocked_count' => count($isolated),
                'downstream_total' => count($descendants),
                'blocked' => array_map(fn (CallTreeTestNode $d) => [
                    'test_node_id' => (int) $d->getKey(),
                    'name' => $d->contact_name_snapshot,
                    'role_label' => $d->role_label_snapshot,
                    'tier' => (int) ($d->tier_snapshot ?? 0),
                ], array_slice($isolated, 0, 50)),
                'headline' => $this->headline($row, count($isolated)),
                'remedies' => $this->remedies($row),
            ];
        }

        usort($branches, fn (array $a, array $b) => $b['downstream_blocked_count'] <=> $a['downstream_blocked_count']);

        return [
            'branches' => $branches,
            'total_blocked' => count($blockedIds),
            'tree' => $this->nested($rows, $children),
        ];
    }

    /**
     * Persist the counts onto the test nodes so the stored record carries them.
     *
     * Called at completion. The screen recomputes for the live view; the number
     * an evidence pack prints in December comes from the column.
     */
    public function persist(CallTreeTest $test): int
    {
        $analysis = $this->analyse($test);

        foreach ($analysis['branches'] as $branch) {
            CallTreeTestNode::query()
                ->whereKey($branch['test_node_id'])
                ->update(['downstream_blocked_count' => $branch['downstream_blocked_count']]);
        }

        return $analysis['total_blocked'];
    }

    private function headline(CallTreeTestNode $row, int $isolated): string
    {
        $name = $row->contact_name_snapshot ?? $row->role_label_snapshot ?? 'This node';

        if ($isolated === 0) {
            return $name.' did not respond. Nobody was isolated by it — everybody below them was '
                .'reached another way.';
        }

        return $name.' '.strtolower((string) $row->outcome?->label()).' → '.$isolated.' '
            .($isolated === 1 ? 'person' : 'staff').' isolated.';
    }

    /**
     * The one-click actions, and which of them this failure actually warrants.
     *
     * A "fix the contact record" button on a node whose number was fine and
     * whose owner simply did not pick up is a button that teaches people to
     * edit records that were not wrong.
     *
     * @return list<array<string, string>>
     */
    private function remedies(CallTreeTestNode $row): array
    {
        $outcome = $row->outcome;
        $out = [];

        if ($outcome !== null && $outcome->isDataQualityFailure()) {
            $out[] = ['action' => 'fix_contact', 'label' => 'Fix the contact record',
                'why' => 'The cascade could not reach this address at all.'];
        }

        if ($row->node !== null && $row->node->deputy_contact_id === null) {
            $out[] = ['action' => 'assign_deputy', 'label' => 'Assign a deputy',
                'why' => 'There was nobody to escalate to when this node did not answer.'];
        }

        $out[] = ['action' => 'reparent', 'label' => 'Re-parent the branch',
            'why' => 'Move the people below this node under somebody who can reach them.'];

        $out[] = ['action' => 'raise_finding', 'label' => 'Raise a corrective action',
            'why' => 'Track the fix in the register, with an owner and a due date.'];

        return $out;
    }

    /**
     * The tree as the screen draws it: nested, with each node's state.
     *
     * @param  Collection<int, CallTreeTestNode>  $rows
     * @param  array<int, list<CallTreeTestNode>>  $children
     * @return list<array<string, mixed>>
     */
    private function nested(Collection $rows, array $children): array
    {
        $build = function (int $parentId, int $depth) use (&$build, &$children): array {
            if ($depth > 12) {
                return [];
            }

            $out = [];

            foreach ($children[$parentId] ?? [] as $row) {
                $outcome = $row->outcome ?? CascadeOutcome::Pending;

                $out[] = [
                    'test_node_id' => (int) $row->getKey(),
                    'node_id' => $row->node_id === null ? null : (int) $row->node_id,
                    'name' => $row->contact_name_snapshot,
                    'role_label' => $row->role_label_snapshot,
                    'tier' => (int) ($row->tier_snapshot ?? 0),
                    'outcome' => $outcome->value,
                    'outcome_label' => $outcome->label(),
                    'state' => $this->state($outcome),
                    'attempts' => (int) $row->attempts,
                    'channel_used' => $row->channel_used,
                    'contacted_at' => $row->contacted_at?->toIso8601String(),
                    'acknowledged_at' => $row->acknowledged_at?->toIso8601String(),
                    'response_minutes' => $row->response_minutes === null ? null : (int) $row->response_minutes,
                    'downstream_blocked_count' => (int) $row->downstream_blocked_count,
                    'is_must_reach' => $row->node !== null && (bool) $row->node->is_must_reach,
                    'children' => $build((int) $row->node_id, $depth + 1),
                ];
            }

            return $out;
        };

        return $build(0, 0);
    }

    /**
     * Green, amber, red or grey — the live map's four colours (Blueprint §6.2).
     *
     * GREY IS ITS OWN STATE AND NOT A SHADE OF RED. A node nobody called has
     * not failed; it was never given the chance. Painting it red blames the
     * wrong person and makes the branch above it look less serious than it is.
     */
    private function state(CascadeOutcome $outcome): string
    {
        return match (true) {
            $outcome === CascadeOutcome::Pending => 'pending',
            $outcome === CascadeOutcome::Blocked => 'blocked',
            $outcome === CascadeOutcome::ConsentBlocked => 'excluded',
            $outcome->isReached() => 'reached',
            default => 'failed',
        };
    }
}
