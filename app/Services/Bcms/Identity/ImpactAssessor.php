<?php

namespace App\Services\Bcms\Identity;

use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\SyncChangeKind;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\SavedGroup;
use App\Services\Bcms\AudienceResolver;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Support\Bcms\AudienceRule;
use Throwable;

/**
 * Call-tree and saved-audience impact of a leaver or a mover — ADR 0018 §3.3.
 *
 * ONLY LEAVERS AND MOVERS ARE ASSESSED. A `contact_change` (a corrected
 * mobile number, a title update) cannot break a call tree or empty an
 * audience; assessing one would be a query that always answers "no impact"
 * and a review-screen badge nobody needed.
 *
 * DOWNSTREAM COUNT IS `CallTreeService::downstreamCount()`, REUSED RATHER
 * THAN RECOMPUTED (work order §4: "reuse it; do not write a second
 * counter"). `BrokenBranchAnalyser`'s "people actually left unreached, never
 * the descendant count" (ADR 0018 §3.3, phase-6 notes §3) is a distinction
 * that only exists WITHIN a live cascade test, where some descendants were
 * reached another way and others were not. There is no cascade running at
 * sync time — nobody has been contacted at all — so the honest worst case a
 * reviewer needs before acknowledging is the full descendant count
 * `CallTreeService` already computes and cycle-guards for the tree screen,
 * and this is recorded as the assumption it is rather than left implicit.
 */
class ImpactAssessor
{
    public function __construct(
        private AudienceResolver $audiences,
        private CallTreeService $trees,
    ) {}

    /**
     * @return array{impact: ?array<string, mixed>, requires_ack: bool}
     */
    public function assess(Contact $contact, SyncChangeKind $kind): array
    {
        if (! in_array($kind, [SyncChangeKind::Leaver, SyncChangeKind::Mover], true)) {
            return ['impact' => null, 'requires_ack' => false];
        }

        $trees = $this->callTreeImpacts($contact);
        $groups = $this->savedGroupImpacts($contact);

        if ($trees === [] && $groups === []) {
            return ['impact' => null, 'requires_ack' => false];
        }

        return [
            'impact' => ['call_trees' => $trees, 'saved_groups' => $groups],
            'requires_ack' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function callTreeImpacts(Contact $contact): array
    {
        $nodes = CallTreeNode::query()
            ->where('contact_id', $contact->getKey())
            ->whereHas('callTree', fn ($q) => $q->where('status', CallTreeStatus::Approved->value))
            ->with('callTree:id,name,status')
            ->get();

        $isNamedDeputy = CallTreeNode::query()
            ->where('deputy_contact_id', $contact->getKey())
            ->whereHas('callTree', fn ($q) => $q->where('status', CallTreeStatus::Approved->value))
            ->exists();

        $out = [];

        foreach ($nodes as $node) {
            $downstream = $this->trees->downstreamCount($node);
            $isSoleMustReach = (bool) $node->is_must_reach && $node->deputy_contact_id === null;

            if ($downstream === 0 && ! $isSoleMustReach) {
                continue;
            }

            $out[] = [
                'tree_id' => (int) $node->call_tree_id,
                'tree_name' => $node->callTree?->name,
                'tier' => (int) $node->tier,
                'downstream_blocked_count' => $downstream,
                'is_sole_must_reach' => $isSoleMustReach,
                'headline' => sprintf(
                    '%s left; they were Tier %d %s with %d downstream staff.',
                    $contact->full_name,
                    (int) $node->tier,
                    $node->callTree->name ?? 'on this tree',
                    $downstream,
                ),
            ];
        }

        if ($isNamedDeputy && $out === []) {
            $out[] = [
                'tree_id' => null,
                'tree_name' => null,
                'tier' => null,
                'downstream_blocked_count' => 0,
                'is_sole_must_reach' => false,
                'is_named_deputy' => true,
                'headline' => $contact->full_name.' is a named deputy on an approved call tree.',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function savedGroupImpacts(Contact $contact): array
    {
        $out = [];

        $staticGroups = SavedGroup::query()
            ->where('is_dynamic', false)
            ->whereHas('members', fn ($q) => $q->where('bcms_contacts.id', $contact->getKey()))
            ->get(['id', 'name']);

        foreach ($staticGroups as $group) {
            $out[] = [
                'group_id' => (int) $group->getKey(),
                'group_name' => $group->name,
                'reason' => 'static_member',
            ];
        }

        $dynamicGroups = SavedGroup::query()
            ->where('organization_id', $contact->organization_id)
            ->where('is_dynamic', true)
            ->whereNotNull('rule')
            ->get(['id', 'name', 'rule']);

        foreach ($dynamicGroups as $group) {
            if (! is_array($group->rule)) {
                continue;
            }

            try {
                $ids = $this->audiences->resolveIds(AudienceRule::fromArray($group->rule));
            } catch (Throwable) {
                // A rule that cannot be resolved (a cycle, an unknown leaf) is
                // already a defect somewhere else in the estate; the identity
                // sync fails closed on it by treating it as "cannot assess",
                // which routes to a human rather than a silent skip.
                $out[] = [
                    'group_id' => (int) $group->getKey(),
                    'group_name' => $group->name,
                    'reason' => 'unresolvable_rule',
                ];

                continue;
            }

            if ($ids->count() === 1 && $ids->contains($contact->getKey())) {
                $out[] = [
                    'group_id' => (int) $group->getKey(),
                    'group_name' => $group->name,
                    'reason' => 'would_empty_dynamic_group',
                ];
            }
        }

        return $out;
    }
}
