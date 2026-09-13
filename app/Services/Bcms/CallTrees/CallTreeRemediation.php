<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\Finding;
use App\Services\Bcms\Findings\FindingService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The four one-click actions on the broken-branch screen (criterion 9).
 *
 * THE POINT IS THAT THE FIX HAPPENS ON THE SCREEN WHERE THE PROBLEM IS VISIBLE.
 * A results page that says "34 staff isolated" and offers no way to act on it
 * teaches people that the test is a reporting exercise. Every one of these four
 * is a repair somebody would otherwise have to do in three other screens, and
 * the reason a call tree stays broken for two years is that nobody had ten
 * minutes to visit all three.
 *
 * TRACK C RAISES NO FINDINGS OF ITS OWN. `raiseFinding()` calls Phase 1's
 * `FindingService` and does nothing else; there is no second register, no
 * second reference sequence and no second overdue count. Orchestration §5 makes
 * this a cross-track contract and criterion 9 checks it with a grep.
 *
 * A REPAIR EDITS THE TREE, NOT THE TEST. The test is evidence of what happened
 * on the day and never changes — the snapshot columns exist for that. Fixing a
 * contact record after a failed cascade must not make the cascade look like it
 * succeeded.
 */
class CallTreeRemediation
{
    public function __construct(
        private FindingService $findings,
        private CallTreeService $trees,
    ) {}

    /**
     * Correct the phone number or email the cascade could not reach.
     *
     * The verification clock restarts and the failure counter resets: an
     * edited number is an unverified number, and carrying the old failure count
     * forward would keep the contact flagged red after it was fixed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fixContact(Contact $contact, array $attributes, ?int $userId = null): Contact
    {
        $allowed = array_intersect_key($attributes, array_flip([
            'mobile_primary', 'mobile_secondary', 'email', 'whatsapp', 'title', 'is_active',
        ]));

        if ($allowed === []) {
            throw new InvalidArgumentException('Nothing to change on this contact record.');
        }

        $before = $contact->only(array_keys($allowed));

        $contact->update(array_merge($allowed, [
            'consecutive_failures' => 0,
            'last_verified_at' => null,
            'verification_status' => 'unverified',
            'updated_by' => $userId ?? auth()->id(),
        ]));

        $contact->recordAudit('contact.repaired_after_cascade', [
            'before' => $before,
            'after' => $allowed,
        ]);

        return $contact->refresh();
    }

    /**
     * Give a node the deputy it did not have.
     */
    public function assignDeputy(CallTreeNode $node, Contact $deputy, ?int $userId = null): CallTreeNode
    {
        if ($deputy->getKey() === $node->contact_id) {
            throw new InvalidArgumentException(
                'A person cannot be their own deputy. Escalating to the person who did not answer is '
                .'not an escalation.'
            );
        }

        $tree = $node->callTree;
        $this->trees->assertEditable($tree);

        $node->update([
            'deputy_contact_id' => $deputy->getKey(),
            'deputy_user_id' => $deputy->user_id,
        ]);

        $this->markEdited($tree, $userId);

        $tree->recordAudit('call_tree.deputy_assigned', [
            'node_id' => (int) $node->getKey(),
            'deputy' => $deputy->full_name,
        ]);

        return $node->refresh();
    }

    /**
     * Move a branch under somebody who can reach it.
     *
     * THE CYCLE CHECK IS NOT OPTIONAL. Re-parenting a node under one of its own
     * descendants makes a loop, and every walk in this module — the downstream
     * count, the cascade release, the broken-branch analysis — would then run
     * until the process runs out of memory. The guards in those walks are a
     * second line of defence; this is the first.
     */
    public function reparent(CallTreeNode $node, ?CallTreeNode $newParent, ?int $userId = null): CallTreeNode
    {
        $tree = $node->callTree;
        $this->trees->assertEditable($tree);

        if ($newParent !== null) {
            if ((int) $newParent->call_tree_id !== (int) $node->call_tree_id) {
                throw new InvalidArgumentException('A node can only be re-parented within its own tree.');
            }

            if ($newParent->getKey() === $node->getKey() || $this->isDescendant($newParent, $node)) {
                throw new InvalidArgumentException(
                    'That would put this node underneath itself. A cascade with a loop in it never '
                    .'finishes.'
                );
            }
        }

        $oldParent = $node->parent_node_id;

        DB::transaction(function () use ($node, $newParent) {
            $node->update([
                'parent_node_id' => $newParent?->getKey(),
                'tier' => $newParent === null ? 0 : min(3, (int) $newParent->tier + 1),
            ]);

            // The tier is a depth, so everybody below moves with them.
            $this->retier($node);
        });

        $this->markEdited($tree, $userId);

        $tree->recordAudit('call_tree.reparented', [
            'node_id' => (int) $node->getKey(),
            'from_parent' => $oldParent === null ? null : (int) $oldParent,
            'to_parent' => $newParent === null ? null : (int) $newParent->getKey(),
        ]);

        return $node->refresh();
    }

    /**
     * Raise the finding, in the Phase 1 register, with source `call_tree_test`.
     */
    public function raiseFinding(
        CallTreeTest $test,
        CallTreeTestNode $testNode,
        ?string $description = null,
        FindingClassification $classification = FindingClassification::Observation,
        ?int $userId = null,
    ): Finding {
        $blocked = (int) $testNode->downstream_blocked_count;

        $text = $description ?? sprintf(
            '%s (%s, tier %d) was not reached during the %s cascade test on %s: %s.%s',
            $testNode->contact_name_snapshot ?? 'A node',
            $testNode->role_label_snapshot ?? 'no role recorded',
            (int) ($testNode->tier_snapshot ?? 0),
            $test->callTree->name,
            $test->initiated_at?->toDateString() ?? 'an unrecorded date',
            strtolower((string) $testNode->outcome?->label()),
            $blocked > 0
                ? ' '.$blocked.' '.($blocked === 1 ? 'person' : 'staff').' below this node were never contacted.'
                : '',
        );

        return $this->findings->raise(
            source: FindingSource::CallTreeTest,
            classification: $classification,
            description: $text,
            sourceRecord: $test,
            attributes: [
                'organization_id' => $test->organization_id,
                'severity' => $blocked >= 25 ? 'high' : ($blocked >= 5 ? 'medium' : 'low'),
                'affected_business_unit_id' => $test->callTree->business_unit_id,
            ],
            userId: $userId,
        );
    }

    /* ------------------------------------------------------------------ */

    private function isDescendant(CallTreeNode $candidate, CallTreeNode $of): bool
    {
        $cursor = $candidate->parent_node_id;
        $depth = 0;

        while ($cursor !== null && $depth++ < 12) {
            if ((int) $cursor === (int) $of->getKey()) {
                return true;
            }

            $parent = CallTreeNode::query()->find($cursor);
            $cursor = $parent?->parent_node_id;
        }

        return false;
    }

    private function retier(CallTreeNode $node, int $depth = 0): void
    {
        if ($depth > 12) {
            return;
        }

        foreach ($node->children()->get() as $child) {
            $child->update(['tier' => min(3, (int) $node->tier + 1)]);
            $this->retier($child, $depth + 1);
        }
    }

    /**
     * A generated tree a human has edited is `hybrid`, not `ad_generated`.
     *
     * The health dashboard leans on the difference: a tree nobody has ever
     * looked at is a different risk from one somebody corrected last week, and
     * both have a recent `updated_at`.
     */
    private function markEdited(CallTree $tree, ?int $userId): void
    {
        $tree->update([
            'source' => $tree->source->afterHumanEdit()->value,
            'updated_by' => $userId ?? auth()->id(),
        ]);
    }
}
