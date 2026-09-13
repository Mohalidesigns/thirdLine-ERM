<?php

namespace App\Services\Tprm\Graph;

use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\ThirdParty;
use Illuminate\Support\Collection;

/**
 * Walking the sub-processor graph — FR-NTH-01.
 *
 * THE CYCLE GUARD IS THE LOAD-BEARING PART. Supply chains genuinely contain
 * loops — a bank's core banking provider hosting on a cloud provider that
 * itself buys switching from a company the bank also uses directly — and a
 * naive walk of that graph does not return. A concentration report that hangs
 * the request is one nobody runs twice, and a rank computation that recurses
 * forever exhausts memory rather than failing cleanly.
 *
 * REACHABILITY IS CHECKED IN THE DATABASE, LEVEL BY LEVEL, rather than by
 * recursion in PHP or a recursive CTE. The CTE spelling differs between this
 * product's two drivers, and the level-by-level walk is bounded by
 * construction: it visits each node once and stops at a stated depth, so the
 * worst case is a wide graph rather than an infinite one.
 *
 * A REJECTED EDGE IS STILL AN EDGE FOR CYCLE PURPOSES — no. It is not, and that
 * is deliberate: a rejected edge is a claim somebody examined and dismissed,
 * and letting it block a later legitimate edge would make a mistaken rejection
 * permanent. `live()` excludes them.
 */
class NthPartyGraph
{
    /**
     * The default depth the graph and the concentration analysis walk to.
     *
     * The constant is the fallback; `defaultDepth()` is what code reads, so a
     * tenant that has set `tprm.scoring.nth_party_depth` gets its own answer.
     */
    public const DEFAULT_DEPTH = 4;

    /**
     * A hard ceiling on the walk, independent of the requested depth.
     *
     * Ten is far beyond any real supply chain and well inside what a request
     * can afford. It exists so that a caller passing a large depth — or a
     * config someone edited — cannot turn a page load into a full graph
     * traversal.
     */
    private const MAX_DEPTH = 10;

    /**
     * Whether adding `parent → child` would create a cycle.
     *
     * True when the PARENT is already reachable FROM the child: that is
     * exactly the path that would close the loop. Checked before the write,
     * because a cycle already in the table cannot be walked to detect itself.
     */
    public function defaultDepth(): int
    {
        return (int) config('tprm.scoring.nth_party_depth', self::DEFAULT_DEPTH);
    }

    public function wouldCycle(int $parentId, ?int $childId): bool
    {
        if ($childId === null) {
            // An unmatched name cannot participate in a cycle: there is no
            // node to come back through. This is the common case and it costs
            // nothing.
            return false;
        }

        if ($parentId === $childId) {
            return true;
        }

        return $this->reaches($childId, $parentId);
    }

    /**
     * Whether `to` is reachable from `from` along live edges.
     *
     * Breadth-first, one query per level, each node visited once. Bounded by
     * `MAX_DEPTH` so a graph that is deeper than any real supply chain still
     * terminates rather than paging through itself.
     */
    public function reaches(int $from, int $to): bool
    {
        $frontier = [$from];
        $seen = [$from => true];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $children = NthPartyEdge::query()
                ->live()
                ->whereIn('parent_third_party_id', $frontier)
                ->whereNotNull('child_third_party_id')
                ->pluck('child_third_party_id')
                ->unique()
                ->all();

            if (in_array($to, $children, true)) {
                return true;
            }

            $frontier = [];

            foreach ($children as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $frontier[] = $child;
                }
            }
        }

        return false;
    }

    /**
     * The graph beneath a set of third parties, to a depth.
     *
     * Returns nodes and edges shaped for the canvas AND for the table
     * equivalent — one traversal serving both, so the accessible view cannot
     * drift from the visual one by being built from a different query.
     *
     * `$confirmedOnly` IS THE DIFFERENCE BETWEEN A VIEW AND A NUMBER. The
     * screen shows proposed edges, because a reviewer cannot confirm what they
     * cannot see. The concentration analysis must not: an HHI that moved
     * because a PDF parser thought it saw "Amazon Web Services" in an annex is
     * a board-level figure resting on an unreviewed machine reading.
     *
     * @param  list<int>  $rootIds
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, truncated: bool}
     */
    public function descendants(array $rootIds, int $depth = self::DEFAULT_DEPTH, bool $confirmedOnly = false): array
    {
        $depth = max(1, min($depth, self::MAX_DEPTH));

        $nodes = [];
        $edges = [];
        $seen = [];
        $frontier = array_values(array_unique(array_filter($rootIds)));
        $truncated = false;

        foreach ($this->parties($frontier) as $party) {
            $nodes[$party->getKey()] = $this->node($party, 0);
            $seen[$party->getKey()] = true;
        }

        for ($level = 1; $level <= $depth && $frontier !== []; $level++) {
            $rows = NthPartyEdge::query()
                ->when($confirmedOnly, fn ($query) => $query->confirmed(), fn ($query) => $query->live())
                ->whereIn('parent_third_party_id', $frontier)
                ->with('child:id,uuid,slug,legal_name,status')
                ->get();

            $next = [];

            foreach ($rows as $edge) {
                $childKey = $edge->child_third_party_id ?? 'raw:'.md5((string) $edge->child_name_raw);

                if (! isset($nodes[$childKey])) {
                    $nodes[$childKey] = $edge->child === null
                        // A named-but-unmatched sub-processor is a NODE, not a
                        // gap. "A large public cloud provider" is real
                        // exposure and a graph that dropped it would understate
                        // the chain.
                        ? [
                            'id' => $childKey,
                            'third_party_id' => null,
                            'name' => $edge->child_name_raw,
                            'depth' => $level,
                            'band' => null,
                            'matched' => false,
                            'url' => null,
                        ]
                        : $this->node($edge->child, $level);
                }

                $edges[] = [
                    'id' => $edge->getKey(),
                    'from' => $edge->parent_third_party_id,
                    'to' => $childKey,
                    'rank' => $edge->rank,
                    'criticality' => $edge->criticality,
                    'service' => $edge->service_description,
                    'country' => $edge->country_of_processing,
                    'disclosure_source' => $edge->disclosure_source->value,
                    'disclosure_label' => $edge->disclosure_source->label(),
                    'vendor_disclosed' => $edge->isVendorDisclosed(),
                    'status' => $edge->confirmation_status,
                    // The edge weight the canvas draws: a critical dependency
                    // is a thicker line, because a graph in which every edge
                    // looks the same tells a reader nothing they could not get
                    // from a list.
                    'weight' => match ($edge->criticality) {
                        'critical' => 4,
                        'high' => 3,
                        'medium' => 2,
                        default => 1,
                    },
                ];

                if ($edge->child_third_party_id !== null && ! isset($seen[$edge->child_third_party_id])) {
                    $seen[$edge->child_third_party_id] = true;
                    $next[] = $edge->child_third_party_id;
                }
            }

            if ($level === $depth && $next !== []) {
                // The graph continues past the depth asked for, and the screen
                // has to say so — a truncated graph presented as complete is
                // the fourth-party equivalent of an empty sanctions list.
                $truncated = true;
            }

            $frontier = $next;
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'truncated' => $truncated,
        ];
    }

    /**
     * Every third party reachable from a root, as a flat list with depth.
     *
     * The table equivalent of the canvas, for WCAG 2.1 AA — built from the
     * same traversal so the two cannot disagree.
     *
     * @return list<array<string, mixed>>
     */
    public function chainTable(int $rootId, int $depth = self::DEFAULT_DEPTH): array
    {
        $graph = $this->descendants([$rootId], $depth);
        $byId = collect($graph['nodes'])->keyBy('id');

        return collect($graph['edges'])
            ->map(fn (array $edge) => [
                'parent' => $byId[$edge['from']]['name'] ?? 'unknown',
                'child' => $byId[$edge['to']]['name'] ?? 'unknown',
                'depth' => $byId[$edge['to']]['depth'] ?? null,
                'service' => $edge['service'],
                'criticality' => $edge['criticality'],
                'country' => $edge['country'],
                'disclosure' => $edge['disclosure_label'],
                'vendor_disclosed' => $edge['vendor_disclosed'],
                'status' => $edge['status'],
                'child_url' => $byId[$edge['to']]['url'] ?? null,
            ])
            ->sortBy(['depth', 'parent', 'child'])
            ->values()
            ->all();
    }

    /**
     * Sub-processors named in evidence that the vendor never declared —
     * FR-NTH's strongest finding.
     *
     * An entity appearing in a SOC 2 carve-out or a discovery pass, and never
     * in anything the vendor itself disclosed, is a broken disclosure
     * obligation the module can PROVE from two rows rather than assert.
     *
     * @return Collection<int, NthPartyEdge>
     */
    public function undeclared(int $parentThirdPartyId)
    {
        $edges = NthPartyEdge::query()
            ->live()
            ->where('parent_third_party_id', $parentThirdPartyId)
            ->get();

        // Grouped by WHO, not by edge: the same sub-processor may be known
        // from three sources, and it is undeclared only if none of the three
        // came from the vendor.
        return $edges
            ->groupBy(fn (NthPartyEdge $edge) => $edge->child_third_party_id
                ?? strtolower(trim($edge->child_name_raw)))
            ->reject(fn (Collection $group) => $group->contains(
                fn (NthPartyEdge $edge) => $edge->isVendorDisclosed()
            ))
            ->map(fn (Collection $group) => $group->first())
            ->values();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ThirdParty>
     */
    private function parties(array $ids)
    {
        return $ids === []
            ? collect()
            : ThirdParty::query()->whereIn('id', $ids)->get(['id', 'uuid', 'slug', 'legal_name', 'status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(ThirdParty $party, int $depth): array
    {
        return [
            'id' => $party->getKey(),
            'third_party_id' => $party->getKey(),
            'name' => $party->legal_name,
            'depth' => $depth,
            // The band an engagement with this party carries, so the canvas can
            // colour by residual risk rather than by nothing.
            'band' => \App\Models\Tprm\Engagement::query()
                ->where('third_party_id', $party->getKey())
                ->whereNotNull('residual_band')
                ->orderByRaw("CASE residual_band WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END")
                ->value('residual_band'),
            'matched' => true,
            'url' => route('tprm.third-parties.show', $party),
        ];
    }
}
