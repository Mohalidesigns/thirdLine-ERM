<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeType;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\BusinessUnit;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Generate from AD" — a tree proposed from the reporting hierarchy.
 *
 * IT READS `bcms_contacts.manager_user_id` AND NOTHING ELSE ABOUT THE
 * DIRECTORY. That column is Phase 2C's output: the staged AD/Entra sync writes
 * it, a human approves the change report, and the value lands. Reading it here
 * rather than calling LDAP means this phase works today against a
 * hand-maintained roster, works unchanged the week the sync goes live, and
 * never has an opinion about which directory a customer runs. Nothing is ever
 * written back (standing rule 3) and there is no code path here that could.
 *
 * IT IS A PROPOSAL AND THE WORD IS LOAD-BEARING. The manager chain says who
 * reports to whom; it does not say who rings whom at three in the morning. The
 * Head of Operations may be on secondment, the night-shift lead may report to
 * somebody in another building, and the person the chain puts at Tier 0 may be
 * on a plane. So generation produces a DRAFT the department head edits, the
 * source becomes `hybrid` the moment they touch it, and the health dashboard
 * distinguishes a tree a human has checked from one that merely exists.
 *
 * DEPUTIES ARE NOT INVENTED. The obvious trick — make the next sibling the
 * deputy — produces a tree where every must-reach node has a deputy who has
 * never been told they are one. A missing deputy is a real gap and belongs on
 * the health dashboard, not papered over by an algorithm (development standard
 * §5: a figure is computed or it is absent).
 */
class TreeProposalService
{
    /** Tier 3 is "all staff" — the ladder stops there however deep the org is. */
    private const MAX_TIER = 3;

    public function __construct(private RcsaScope $scope, private CallTreeService $trees) {}

    /**
     * What the tree WOULD look like, without writing anything.
     *
     * The designer shows this before it commits, because a generated tree that
     * turns out to be wrong is far cheaper to reject than to unpick.
     *
     * @return array{nodes: list<array<string, mixed>>, unplaced: list<array<string, mixed>>, tiers: array<int, int>, total: int, depth: int}
     */
    public function preview(BusinessUnit $unit, CallTreeType $type = CallTreeType::Department): array
    {
        if (! $type->followsManagerChain()) {
            throw new InvalidArgumentException(
                $type->label().' trees are appointments, not reporting lines. Generating one from the '
                .'manager chain would produce a tree that looks authoritative and names the wrong people.'
            );
        }

        $contacts = $this->rosterFor($unit);

        return $this->arrange($contacts);
    }

    /**
     * Generate and persist a draft tree.
     */
    public function generate(
        BusinessUnit $unit,
        CallTreeType $type = CallTreeType::Department,
        ?string $name = null,
        ?int $userId = null,
    ): CallTree {
        $plan = $this->preview($unit, $type);

        if ($plan['total'] === 0) {
            throw new InvalidArgumentException(
                'No contacts in this business unit carry a manager link, so there is no chain to '
                .'generate from. Build the tree by hand, or run the directory sync first.'
            );
        }

        return DB::transaction(function () use ($unit, $type, $name, $userId, $plan) {
            $tree = $this->trees->create([
                'business_unit_id' => $unit->getKey(),
                'name' => $name ?? $unit->name.' call tree',
                'tree_type' => $type->value,
                'source' => CallTreeSource::AdGenerated->value,
            ], $userId);

            $this->persist($tree, $plan['nodes'], null);

            $tree->recordAudit('call_tree.generated', [
                'business_unit_id' => (int) $unit->getKey(),
                'nodes' => $plan['total'],
                'depth' => $plan['depth'],
                'unplaced' => count($plan['unplaced']),
            ]);

            return $tree->refresh();
        });
    }

    /**
     * The contacts a tree over this unit would be drawn from.
     *
     * The unit's whole SUBTREE, not just its own row: a department call tree
     * that stopped at the department's direct members would exclude every team
     * inside it, which for Operations is most of the two hundred people the
     * cascade exists to reach.
     *
     * @return Collection<int, Contact>
     */
    private function rosterFor(BusinessUnit $unit): Collection
    {
        $units = $this->scope->subtreeOf((int) $unit->getKey(), (int) $unit->organization_id);

        return Contact::query()
            ->where('is_active', true)
            ->whereIn('business_unit_id', $units)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Turn a flat roster into tiers.
     *
     * THE CHAIN IS WALKED WITH A VISITED SET. `manager_user_id` is user-editable
     * data and a directory export with a two-person management loop in it is not
     * hypothetical; without the guard the depth walk is an infinite recursion
     * that ends as a 512 MB out-of-memory error rather than an error message.
     *
     * @param  Collection<int, Contact>  $contacts
     * @return array{nodes: list<array<string, mixed>>, unplaced: list<array<string, mixed>>, tiers: array<int, int>, total: int, depth: int}
     */
    private function arrange(Collection $contacts): array
    {
        /** @var array<int, Contact> $byUser */
        $byUser = [];
        foreach ($contacts as $contact) {
            if ($contact->user_id !== null) {
                $byUser[(int) $contact->user_id] = $contact;
            }
        }

        // A manager who is not in this roster is outside the department, so the
        // person reporting to them is a root of this tree.
        $managerOf = function (Contact $contact) use ($byUser): ?Contact {
            $managerUserId = $contact->manager_user_id;

            if ($managerUserId === null) {
                return null;
            }

            $manager = $byUser[(int) $managerUserId] ?? null;

            return $manager !== null && $manager->getKey() !== $contact->getKey() ? $manager : null;
        };

        $depthOf = [];
        $unplaced = [];

        foreach ($contacts as $contact) {
            $depth = 0;
            $seen = [(int) $contact->getKey() => true];
            $cursor = $contact;

            while (($manager = $managerOf($cursor)) !== null) {
                if (isset($seen[(int) $manager->getKey()])) {
                    // A loop. The person is placed at the depth reached so far
                    // and the loop is reported rather than silently flattened —
                    // a management cycle is a real defect in the directory.
                    $unplaced[] = [
                        'contact_id' => (int) $contact->getKey(),
                        'name' => $contact->full_name,
                        'reason' => 'A management loop was found walking this person\'s reporting chain.',
                    ];
                    break;
                }

                $seen[(int) $manager->getKey()] = true;
                $cursor = $manager;
                $depth++;

                if ($depth > 20) {
                    break;
                }
            }

            $depthOf[(int) $contact->getKey()] = $depth;
        }

        $maxDepth = $depthOf === [] ? 0 : max($depthOf);

        $children = [];
        foreach ($contacts as $contact) {
            $manager = $managerOf($contact);
            $children[(int) ($manager?->getKey() ?? 0)][] = $contact;
        }

        $build = function (?int $parentId, int $depth) use (&$build, &$children): array {
            if ($depth > 20) {
                return [];
            }

            $out = [];

            foreach ($children[(int) $parentId] ?? [] as $index => $contact) {
                $out[] = [
                    'contact_id' => (int) $contact->getKey(),
                    'user_id' => $contact->user_id === null ? null : (int) $contact->user_id,
                    'name' => $contact->full_name,
                    'title' => $contact->title,
                    // Depth beyond tier 3 collapses onto tier 3. A nine-level
                    // bank is still a four-tier cascade; the extra levels are
                    // reporting structure, not calling structure.
                    'tier' => min($depth, self::MAX_TIER),
                    'role_label' => $contact->title,
                    'sequence' => $index,
                    'is_must_reach' => $depth <= 1,
                    'expected_response_minutes' => $depth === 0 ? 10 : ($depth === 1 ? 15 : 30),
                    'children' => $build((int) $contact->getKey(), $depth + 1),
                ];
            }

            return $out;
        };

        $nodes = $build(0, 0);

        $tiers = [];
        $count = 0;
        $walk = function (array $rows) use (&$walk, &$tiers, &$count): void {
            foreach ($rows as $row) {
                $tiers[$row['tier']] = ($tiers[$row['tier']] ?? 0) + 1;
                $count++;
                $walk($row['children']);
            }
        };
        $walk($nodes);
        ksort($tiers);

        return [
            'nodes' => $nodes,
            'unplaced' => $unplaced,
            'tiers' => $tiers,
            'total' => $count,
            'depth' => $maxDepth + 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(CallTree $tree, array $rows, ?int $parentId): void
    {
        foreach ($rows as $row) {
            $node = CallTreeNode::query()->create([
                'organization_id' => $tree->organization_id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $parentId,
                'tier' => $row['tier'],
                'user_id' => $row['user_id'],
                'contact_id' => $row['contact_id'],
                'role_label' => $row['role_label'],
                'is_must_reach' => $row['is_must_reach'],
                'expected_response_minutes' => $row['expected_response_minutes'],
                'sequence' => $row['sequence'],
            ]);

            $this->persist($tree, $row['children'], (int) $node->getKey());
        }
    }

    /**
     * Business units a tree could be generated over — those with contacts that
     * carry a manager link.
     *
     * @return Collection<int, BusinessUnit>
     */
    public function generatableUnits(): Collection
    {
        return BusinessUnit::query()
            ->whereIn('id', Contact::query()
                ->where('is_active', true)
                ->whereNotNull('business_unit_id')
                ->select('business_unit_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * How complete the directory link is — shown beside the generate button so
     * nobody is surprised by a two-node tree.
     *
     * @return array{contacts: int, with_manager: int, coverage: ?float}
     */
    public function chainCoverage(BusinessUnit $unit): array
    {
        $units = $this->scope->subtreeOf((int) $unit->getKey(), (int) $unit->organization_id);

        $base = Contact::query()->where('is_active', true)->whereIn('business_unit_id', $units);
        $total = (clone $base)->count();
        $linked = (clone $base)->whereNotNull('manager_user_id')->count();

        return [
            'contacts' => $total,
            'with_manager' => $linked,
            // Null, not zero. An empty unit has no coverage figure; reporting
            // 0% would read as a data problem rather than an empty department.
            'coverage' => $total === 0 ? null : round($linked / $total * 100, 1),
        ];
    }
}
