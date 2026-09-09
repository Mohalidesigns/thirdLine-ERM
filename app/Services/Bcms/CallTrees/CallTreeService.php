<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CallTreeType;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Call trees: their structure, their versions and their health.
 *
 * A LAMINATED SHEET ON A WALL IS THE THING THIS REPLACES, and the two ways it
 * fails are the two things this service is mostly about. It goes out of date
 * and nobody notices — so staleness is computed from the review date rather
 * than stored (ADR 0013), and it is true the moment it is true rather than the
 * moment a job runs. And it names people who have left — so an orphaned node is
 * a first-class query with a downstream count beside it, because "Ibrahim
 * resigned" and "Ibrahim resigned and thirty-four people now have nobody to
 * call them" are different problems and only the second gets fixed.
 *
 * AN APPROVED TREE IS NEVER EDITED. `supersede()` builds v2 as a new row with
 * its nodes copied, and v1 becomes `archived` with its node rows intact — a
 * test that ran against v1 still points at the people who were actually on it.
 * This is the same rule as the BC policy and as plans, implemented the same way
 * on purpose (ADR 0013).
 */
class CallTreeService
{
    public function __construct(private RcsaScope $scope) {}

    /* ------------------------------------------------------------------ */
    /*  Queries */
    /* ------------------------------------------------------------------ */

    /**
     * Current trees — the newest version of each chain, not the whole history.
     *
     * @return Builder<CallTree>
     */
    public function currentQuery(?User $user = null): Builder
    {
        $query = CallTree::query()->where('status', '!=', CallTreeStatus::Archived->value);

        return $user === null ? $query : CallTree::visibleQuery($query, $user);
    }

    /**
     * Trees whose review is overdue.
     *
     * THE SQL AND `isStale()` HAVE TO AGREE. The cutoff is per row, because
     * `review_frequency_days` varies by tree type, and the obvious way to write
     * that — `DATE_SUB(NOW(), INTERVAL review_frequency_days DAY)` — is MariaDB
     * dialect. Phase 4 already shipped one MariaDB-only predicate that passed
     * every SQLite test and would have failed on the only database a customer
     * runs, so this builds one arm per distinct cadence present instead. There
     * are three in practice (90, 120, 180).
     *
     * A tree that has never been reviewed is stale from the day it was
     * approved. Null `last_reviewed_at` with an approval behind it is the
     * commonest real case, and reading it as "not yet due" would hide every
     * tree that was approved and then forgotten — which is the exact failure
     * this module exists to catch.
     *
     * @return Builder<CallTree>
     */
    public function staleQuery(?User $user = null): Builder
    {
        $cadences = $this->currentQuery($user)
            ->distinct()->pluck('review_frequency_days')
            ->map(fn ($d) => max(1, (int) $d))->unique()->values()->all();

        $query = $this->currentQuery($user);

        if ($cadences === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($cadences) {
            foreach ($cadences as $days) {
                $cutoff = Carbon::now()->subDays($days);

                $outer->orWhere(function (Builder $arm) use ($days, $cutoff) {
                    $arm->where('review_frequency_days', $days)
                        ->where(function (Builder $anchor) use ($cutoff) {
                            $anchor->where('last_reviewed_at', '<', $cutoff)
                                ->orWhere(fn (Builder $never) => $never
                                    ->whereNull('last_reviewed_at')
                                    ->where('approved_at', '<', $cutoff));
                        });
                });
            }
        });
    }

    /**
     * Is this tree overdue for review?
     *
     * The per-row rule, and the one the badge on screen uses.
     */
    public function isStale(CallTree $tree): bool
    {
        $anchor = $tree->last_reviewed_at ?? $tree->approved_at;

        if ($anchor === null) {
            // A draft nobody has approved is not stale, it is unfinished. The
            // dashboard counts it under a different heading, because "approve
            // this" and "review this" are different jobs for different people.
            return false;
        }

        return $anchor->copy()->addDays(max(1, (int) $tree->review_frequency_days))->isPast();
    }

    public function daysOverdue(CallTree $tree): ?int
    {
        $anchor = $tree->last_reviewed_at ?? $tree->approved_at;

        if ($anchor === null) {
            return null;
        }

        $due = $anchor->copy()->addDays(max(1, (int) $tree->review_frequency_days));

        return $due->isPast() ? (int) abs($due->diffInDays(Carbon::now())) : null;
    }

    /**
     * The full version chain for a tree, newest first.
     *
     * Walks both ways from wherever it was handed: forwards through
     * `supersededBy` and back through `supersedes`. Being given v2 of five and
     * getting two versions back would be worse than an error, because it looks
     * like an answer.
     *
     * @return Collection<int, CallTree>
     */
    public function versions(CallTree $tree): Collection
    {
        $chain = collect([$tree]);
        $seen = [(int) $tree->getKey() => true];

        $cursor = $tree;
        while (($previous = $cursor->supersedes) !== null && ! isset($seen[(int) $previous->getKey()])) {
            $seen[(int) $previous->getKey()] = true;
            $chain->push($previous);
            $cursor = $previous;
        }

        $cursor = $tree;
        while (true) {
            $next = CallTree::query()->where('supersedes_call_tree_id', $cursor->getKey())->first();

            if ($next === null || isset($seen[(int) $next->getKey()])) {
                break;
            }

            $seen[(int) $next->getKey()] = true;
            $chain->push($next);
            $cursor = $next;
        }

        return $chain->sortByDesc(fn (CallTree $t) => [$t->approved_at?->getTimestamp() ?? 0, $t->getKey()])
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?int $userId = null): CallTree
    {
        $type = $attributes['tree_type'] instanceof CallTreeType
            ? $attributes['tree_type']
            : CallTreeType::from((string) ($attributes['tree_type'] ?? CallTreeType::Department->value));

        return CallTree::query()->create(array_merge([
            'tree_type' => $type->value,
            'version' => '1.0',
            'status' => CallTreeStatus::Draft->value,
            'source' => CallTreeSource::Manual->value,
            'review_frequency_days' => $type->defaultReviewDays(),
            'iso_clause_ref' => 'ISO22301:8.4.3',
            'created_by' => $userId ?? auth()->id(),
        ], $attributes, ['tree_type' => $type->value]));
    }

    /** @param array<string, mixed> $attributes */
    public function update(CallTree $tree, array $attributes, ?int $userId = null): CallTree
    {
        $this->assertEditable($tree);

        $tree->update(array_merge($attributes, ['updated_by' => $userId ?? auth()->id()]));

        return $tree->refresh();
    }

    /**
     * The department head signs the tree off.
     *
     * Approval sets `last_reviewed_at` as well as `approved_at`: approving a
     * tree IS reviewing it, and leaving the review clock unstarted would show a
     * tree as never-reviewed on the day it was signed.
     */
    public function approve(CallTree $tree, ?int $userId = null): CallTree
    {
        if ($tree->status !== CallTreeStatus::Draft) {
            throw new InvalidArgumentException('Only a draft call tree can be approved.');
        }

        if ($tree->nodes()->count() === 0) {
            throw new InvalidArgumentException(
                'A call tree with no nodes cannot be approved. An empty cascade approved by a '
                .'department head is worse than no cascade, because somebody believes it exists.'
            );
        }

        $tree->update([
            'status' => CallTreeStatus::Approved->value,
            'approved_by' => $userId ?? auth()->id(),
            'approved_at' => now(),
            'last_reviewed_at' => now(),
            'updated_by' => $userId ?? auth()->id(),
        ]);

        $tree->recordAudit('call_tree.approved', [
            'version' => $tree->version,
            'nodes' => $tree->nodes()->count(),
        ]);

        return $tree->refresh();
    }

    /**
     * Create the next version, archiving the one it replaces.
     *
     * The nodes are copied, including their parent links, which is the fiddly
     * part: a copied child has to point at the copied parent and not at the
     * original, or v2's tree is v1's tree wearing a new id.
     */
    public function supersede(CallTree $approved, ?string $newVersion = null, ?int $userId = null): CallTree
    {
        if ($approved->status !== CallTreeStatus::Approved) {
            throw new InvalidArgumentException('Only an approved call tree version can be superseded.');
        }

        return DB::transaction(function () use ($approved, $newVersion, $userId) {
            $next = CallTree::query()->create([
                'organization_id' => $approved->organization_id,
                'business_unit_id' => $approved->business_unit_id,
                'site_id' => $approved->site_id,
                'name' => $approved->name,
                'tree_type' => $approved->tree_type->value,
                'version' => $newVersion ?? $this->nextVersion($approved->version),
                'supersedes_call_tree_id' => $approved->getKey(),
                'status' => CallTreeStatus::Draft->value,
                'review_frequency_days' => $approved->review_frequency_days,
                'source' => $approved->source->value,
                'activation_authority_user_id' => $approved->activation_authority_user_id,
                'iso_clause_ref' => $approved->iso_clause_ref,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $this->copyNodes($approved, $next);

            $approved->update([
                'status' => CallTreeStatus::Archived->value,
                'updated_by' => $userId ?? auth()->id(),
            ]);

            $next->recordAudit('call_tree.superseded', [
                'supersedes' => $approved->version,
                'version' => $next->version,
            ]);

            return $next->refresh();
        });
    }

    /** Record a review that changed nothing — the clock restarts, the version does not. */
    public function markReviewed(CallTree $tree, ?int $userId = null): CallTree
    {
        $tree->update(['last_reviewed_at' => now(), 'updated_by' => $userId ?? auth()->id()]);
        $tree->recordAudit('call_tree.reviewed', ['version' => $tree->version]);

        return $tree->refresh();
    }

    private function copyNodes(CallTree $from, CallTree $to): void
    {
        /** @var Collection<int, CallTreeNode> $nodes */
        $nodes = $from->nodes()->orderBy('tier')->orderBy('sequence')->orderBy('id')->get();

        $map = [];

        // Ordered by tier, so a parent is always copied before its children.
        foreach ($nodes as $node) {
            $copy = CallTreeNode::query()->create([
                'organization_id' => $to->organization_id,
                'call_tree_id' => $to->getKey(),
                'parent_node_id' => $node->parent_node_id === null
                    ? null
                    : ($map[(int) $node->parent_node_id] ?? null),
                'tier' => $node->tier,
                'user_id' => $node->user_id,
                'contact_id' => $node->contact_id,
                'role_label' => $node->role_label,
                'is_must_reach' => $node->is_must_reach,
                'primary_channel' => $node->primary_channel,
                'secondary_channel' => $node->secondary_channel,
                'deputy_user_id' => $node->deputy_user_id,
                'deputy_contact_id' => $node->deputy_contact_id,
                'expected_response_minutes' => $node->expected_response_minutes,
                'sequence' => $node->sequence,
                'notes' => $node->notes,
            ]);

            $map[(int) $node->getKey()] = (int) $copy->getKey();
        }
    }

    private function nextVersion(string $current): string
    {
        $major = (int) explode('.', $current)[0];

        return ($major + 1).'.0';
    }

    public function assertEditable(CallTree $tree): void
    {
        if (! $tree->status->isEditable()) {
            throw new InvalidArgumentException(
                'An approved call tree cannot be edited. Supersede it with a new version — the roster '
                .'that was cascaded in March has to still be the roster that was cascaded in March.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Structure */
    /* ------------------------------------------------------------------ */

    /**
     * The tree, nested, with a health verdict and a downstream count on every
     * node. THE ONE SHAPE the designer, the live map and the results screen all
     * read — three renderers computing "how many people are below this" three
     * ways is three chances to disagree about the number the demo turns on.
     *
     * `orphans` counts everybody the cascade could not reach — a person who
     * has left AND a person whose number is dead. `orphanedNodes()` returns
     * the same set with the two causes labelled.
     *
     * @return array{nodes: list<array<string, mixed>>, index: array<int, array<string, mixed>>, orphans: int, missing_deputies: int, total: int}
     */
    public function structure(CallTree $tree): array
    {
        /** @var Collection<int, CallTreeNode> $nodes */
        $nodes = $tree->nodes()
            ->with(['contact:id,full_name,title,mobile_primary,email,is_active,consent_status,last_verified_at,consecutive_failures,user_id',
                'deputyContact:id,full_name,mobile_primary,is_active,consent_status',
                'user:id,name'])
            ->orderBy('tier')->orderBy('sequence')->orderBy('id')
            ->get();

        $children = [];
        foreach ($nodes as $node) {
            $children[(int) ($node->parent_node_id ?? 0)][] = $node;
        }

        $index = [];
        $orphans = 0;
        $missingDeputies = 0;

        $build = function (?int $parentId, int $depth) use (&$build, &$children, &$index, &$orphans, &$missingDeputies): array {
            // A parent cycle is a 512 MB out-of-memory error, not an exception,
            // and this walk is over user-editable parent links.
            if ($depth > 12) {
                return [];
            }

            $out = [];

            foreach ($children[(int) $parentId] ?? [] as $node) {
                $kids = $build((int) $node->getKey(), $depth + 1);

                $descendants = count($kids);
                foreach ($kids as $kid) {
                    $descendants += (int) $kid['downstream_count'];
                }

                $health = $this->nodeHealth($node);

                // Locals: both relations are genuinely nullable at runtime and
                // typed non-null by static analysis.
                $contact = $node->contact;
                $user = $node->user;

                // UNREACHABLE, not merely orphaned. A node whose person has
                // left and a node whose person is there with a dead number are
                // different problems with different owners — `orphanedNodes()`
                // labels them separately — but both are somebody the cascade
                // cannot reach, and the count on the dashboard has to be the
                // same count as the list on the designer. Two screens
                // disagreeing about how many people are unreachable is worse
                // than either number alone.
                if ($health['is_orphaned'] || $health['has_no_address']) {
                    $orphans++;
                }

                if ($health['missing_deputy']) {
                    $missingDeputies++;
                }

                $row = array_merge([
                    'id' => (int) $node->getKey(),
                    'parent_id' => $node->parent_node_id === null ? null : (int) $node->parent_node_id,
                    'tier' => (int) $node->tier,
                    'sequence' => (int) $node->sequence,
                    'role_label' => $node->role_label,
                    'name' => $contact !== null ? $contact->full_name : $user?->name,
                    'title' => $contact?->title,
                    'contact_id' => $node->contact_id === null ? null : (int) $node->contact_id,
                    'user_id' => $node->user_id === null ? null : (int) $node->user_id,
                    'is_must_reach' => (bool) $node->is_must_reach,
                    'primary_channel' => $node->primary_channel,
                    'secondary_channel' => $node->secondary_channel,
                    'deputy_name' => $node->deputyContact?->full_name,
                    'deputy_contact_id' => $node->deputy_contact_id === null ? null : (int) $node->deputy_contact_id,
                    'expected_response_minutes' => (int) $node->expected_response_minutes,
                    'notes' => $node->notes,
                    'downstream_count' => $descendants,
                    'children' => $kids,
                ], $health);

                $index[(int) $node->getKey()] = $row;
                $out[] = $row;
            }

            return $out;
        };

        $roots = $build(0, 0);

        return [
            'nodes' => $roots,
            'index' => $index,
            'orphans' => $orphans,
            'missing_deputies' => $missingDeputies,
            'total' => $nodes->count(),
        ];
    }

    /**
     * How many people are below this node — the broken-branch number, computed
     * from the tree rather than from a test.
     */
    public function downstreamCount(CallTreeNode $node): int
    {
        $structure = $this->structure($node->callTree);

        return (int) ($structure['index'][(int) $node->getKey()]['downstream_count'] ?? 0);
    }

    /**
     * What is wrong with this node, if anything.
     *
     * ORPHANED IS NOT THE SAME AS UNREACHABLE. A node whose person has left is
     * orphaned — the record points at nobody, and no amount of dialling fixes
     * it. A node whose person is there but has no mobile number is a data
     * problem with a different owner and a different fix. Collapsing them into
     * "broken" produces a list nobody can work through.
     *
     * @return array<string, mixed>
     */
    private function nodeHealth(CallTreeNode $node): array
    {
        $contact = $node->contact;

        $orphaned = $contact === null || ! $contact->is_active;
        $unverified = $contact !== null && $contact->last_verified_at === null;
        $consentWithdrawn = $contact !== null && $contact->consent_status === 'withdrawn';
        $noAddress = $contact !== null
            && ($contact->mobile_primary === null || $contact->mobile_primary === '')
            && ($contact->email === null || $contact->email === '');

        return [
            'is_orphaned' => $orphaned,
            'is_unverified' => $unverified,
            'consent_withdrawn' => $consentWithdrawn,
            'has_no_address' => $noAddress,
            'failing_channel' => $contact !== null && (int) $contact->consecutive_failures >= 3,
            // A deputy is only expected where a failure would be expensive.
            // Demanding one on every leaf produces two hundred warnings and
            // teaches everybody to ignore the column.
            'missing_deputy' => (bool) $node->is_must_reach && $node->deputy_contact_id === null,
        ];
    }

    /**
     * Nodes nobody can reach — the nightly sync's output, and criterion 6.
     *
     * A LEAVER AND A BAD NUMBER ARE BOTH HERE AND ARE LABELLED DIFFERENTLY.
     * They have different owners (HR against whoever maintains the roster) and
     * different fixes, and a single "broken" list is one nobody works through.
     * The downstream count travels with each row, because the fix gets
     * prioritised by how many people it unblocks and by nothing else.
     *
     * @return list<array<string, mixed>>
     */
    public function orphanedNodes(CallTree $tree): array
    {
        $rows = [];

        foreach ($this->structure($tree)['index'] as $row) {
            if ($row['is_orphaned']) {
                $reason = 'The person on this node is no longer an active contact.';
            } elseif ($row['has_no_address']) {
                $reason = 'This node has no usable address on any channel.';
            } else {
                continue;
            }

            $rows[] = [
                'id' => $row['id'],
                'tier' => $row['tier'],
                'role_label' => $row['role_label'],
                'name' => $row['name'],
                'downstream_count' => $row['downstream_count'],
                'reason' => $reason,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['downstream_count'] <=> $a['downstream_count']);

        return $rows;
    }

    /**
     * Contacts eligible to sit on this tree — the designer's person picker.
     *
     * @return Collection<int, Contact>
     */
    public function candidateContacts(CallTree $tree, ?string $search = null): Collection
    {
        $query = Contact::query()->where('is_active', true);

        if ($tree->business_unit_id !== null) {
            $units = $this->scope->subtreeOf((int) $tree->business_unit_id, (int) $tree->organization_id);
            $query->where(fn (Builder $q) => $q->whereIn('business_unit_id', $units)->orWhereNull('business_unit_id'));
        }

        if ($search !== null && $search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('full_name', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('employee_id', 'like', '%'.$search.'%'));
        }

        return $query->orderBy('full_name')->limit(50)->get();
    }
}
