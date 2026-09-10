<?php

namespace Database\Seeders\Bcms;

use App\Enums\Bcms\CallTreeSource;
use App\Enums\Bcms\CallTreeStatus;
use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Enums\Bcms\ContactSource;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\CallTreeTestNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\CascadeEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The call-tree demo estate: 22 trees, ~330 contacts, and one deliberately
 * broken branch.
 *
 * EVERY NUMBER IS IN THE RESERVED +2348000 RANGE AND DOES NOT ROUTE. The
 * existing roster seeder says why and this obeys the same rule: a seeder that
 * wrote a plausible Nigerian mobile into a table an EMNS dispatcher reads is one
 * misconfiguration away from ringing a stranger during a demo.
 *
 * THE OPERATIONS TREE IS THE DEMO AND IS BUILT TO BE ONE. Four tiers, two
 * hundred staff, and a Tier-2 unit lead whose number is wrong — thirty-four
 * people below them. That is the sentence the broken-branch screen prints, and
 * every part of it is real data the analyser computes rather than a fixture.
 *
 * THE FLAWS ARE DELIBERATE AND ARE THE POINT. Three stale trees, five must-reach
 * nodes with no deputy, eight bad numbers, one contact who has withdrawn
 * consent. A demo estate where everything is green demonstrates nothing: the
 * screens exist to show problems, and a screen with no problems on it is a
 * screen nobody can evaluate.
 */
class CallTreeDemoSeeder
{
    /** The twelve departments that get a tree, in Blueprint §6.1's sense. */
    private const DEPARTMENTS = [
        ['BU-OP', 'Operations', 200],
        ['BU-IT', 'Information Technology', 34],
        ['BU-RT', 'Retail Banking', 28],
        ['BU-TR', 'Treasury', 12],
        ['BU-ERM', 'Enterprise Risk Management', 10],
        ['BU-CIC', 'Compliance', 9],
        ['BU-FC', 'Finance and Control', 14],
        ['BU-HR', 'Human Resources', 8],
        ['BU-IA', 'Internal Audit', 7],
        ['BU-LG', 'Legal', 5],
        ['BU-CX', 'Customer Experience', 16],
        ['BU-IB', 'Corporate Banking', 11],
    ];

    private const FIRST_NAMES = [
        'Ibrahim', 'Amina', 'Chidi', 'Ngozi', 'Musa', 'Folake', 'Emeka', 'Halima', 'Tunde', 'Aisha',
        'Segun', 'Zainab', 'Obinna', 'Yetunde', 'Sani', 'Blessing', 'Kabiru', 'Chioma', 'Bashir', 'Funke',
        'Nnamdi', 'Maryam', 'Olumide', 'Hauwa', 'Uche', 'Adaeze', 'Suleiman', 'Bukola', 'Ifeanyi', 'Rukayat',
    ];

    private const LAST_NAMES = [
        'Sani', 'Okonkwo', 'Adeyemi', 'Bello', 'Eze', 'Abubakar', 'Nwosu', 'Lawal', 'Okafor', 'Yusuf',
        'Adebayo', 'Danjuma', 'Uzoma', 'Balogun', 'Ibrahim', 'Chukwu', 'Aliyu', 'Ogundele', 'Musa', 'Nnaji',
    ];

    /** Reports per unit lead, before the headline branch is carved out. */
    private const SPAN = 8;

    /** The size of the branch the demo breaks. */
    private const HEADLINE_BRANCH = 34;

    private int $sequence = 0;

    public function run(Organization $organization): void
    {
        if (CallTree::query()->exists()) {
            return;
        }

        $units = BusinessUnit::query()->pluck('id', 'code');
        $sites = Site::query()->pluck('id', 'code');
        $hqId = $sites['HQ'] ?? null;

        $departmentTrees = [];

        foreach (self::DEPARTMENTS as $index => [$code, $label, $headcount]) {
            $unitId = $units[$code] ?? null;

            if ($unitId === null) {
                continue;
            }

            $roster = $this->roster($organization, (int) $unitId, $hqId, $label, $headcount);

            $tree = $this->tree($organization, [
                'business_unit_id' => (int) $unitId,
                'site_id' => $hqId,
                'name' => $label.' call tree',
                'tree_type' => CallTreeType::Department,
                // The three stale ones. Reviewed far enough back that they are
                // unambiguously overdue, not borderline.
                'reviewed_days_ago' => in_array($index, [3, 7, 9], true) ? 400 : 30,
                'source' => $index % 3 === 0 ? CallTreeSource::Hybrid : CallTreeSource::Manual,
            ]);

            // Deputies on every node that has a peer. The five that are
            // MISSING are removed deliberately below, and a baseline where
            // most nodes already lacked one would hide them.
            $this->build($tree, $roster);
            $departmentTrees[$code] = $tree;
        }

        $this->seedCrisisTeam($organization, $hqId);
        $this->seedExecutive($organization, $hqId);
        $this->seedBranchTrees($organization, $units, $sites);

        if (isset($departmentTrees['BU-OP'])) {
            $this->breakTheOperationsTree($departmentTrees['BU-OP']);
        }

        $this->seedDataFlaws();

        if (isset($departmentTrees['BU-OP'])) {
            $this->runTheDemoCascade($departmentTrees['BU-OP']);
        }
    }

    /**
     * Run one cascade for real, so the estate ships with the screen the demo
     * opens on.
     *
     * NOTHING IS FIXTURED. The engine dispatches through the Phase 0 mocks, the
     * people it could reach acknowledge, the broken unit lead cannot be reached
     * and the analyser computes what that cost. If the count on the screen is
     * wrong, it is wrong because the code is wrong — which is the only way a
     * demo estate is worth having.
     */
    private function runTheDemoCascade(CallTree $tree): void
    {
        $engine = app(CascadeEngine::class);

        $test = $engine->schedule($tree, CascadeMode::Hybrid, announced: true);
        $engine->initiate($test);

        // Everybody the cascade actually contacted answers, a few minutes
        // apart, so the per-tier timings are not all zero.
        for ($pass = 0; $pass < 8; $pass++) {
            $waiting = CallTreeTestNode::query()
                ->where('test_id', $test->getKey())
                ->where('outcome', CascadeOutcome::Pending->value)
                ->whereNotNull('contacted_at')
                ->get();

            if ($waiting->isEmpty()) {
                break;
            }

            foreach ($waiting as $row) {
                $engine->acknowledge(
                    $row,
                    via: CascadeEngine::CHANNEL_IN_APP,
                    at: ($row->contacted_at ?? now())->copy()->addMinutes(2 + ($row->getKey() % 9)),
                );
            }
        }

        $engine->complete($test);
    }

    /* ------------------------------------------------------------------ */
    /*  People */
    /* ------------------------------------------------------------------ */

    /**
     * A department's roster, with the manager links "Generate from AD" reads.
     *
     * The chain is built as it is seeded — each person's manager is somebody
     * already created above them — so `manager_user_id` describes a real
     * hierarchy rather than a shuffle. Phase 2C replaces this with the
     * directory; the column and its meaning do not change.
     *
     * @return Collection<int, Contact>
     */
    private function roster(Organization $organization, int $unitId, ?int $siteId, string $label, int $headcount): Collection
    {
        $existing = Contact::query()->where('business_unit_id', $unitId)->get();

        if ($existing->count() >= $headcount) {
            return $existing;
        }

        $created = collect();

        // Tier 0 — the head of department.
        $head = $this->contact($organization, $unitId, $siteId, 'Head of '.$label, null);
        $created->push($head);

        // Tier 1 — section heads, one per eight people.
        $sectionCount = max(1, (int) ceil(($headcount - 1) / 40));
        $sections = [];
        for ($i = 0; $i < $sectionCount; $i++) {
            $sections[] = $this->contact($organization, $unitId, $siteId, $label.' section head', $head);
        }
        $created = $created->merge($sections);

        // Tier 2 — unit leads under each section head.
        $leadCount = max(1, (int) ceil(($headcount - 1 - $sectionCount) / self::SPAN));
        $leads = [];
        for ($i = 0; $i < $leadCount; $i++) {
            $leads[] = $this->contact($organization, $unitId, $siteId, $label.' unit lead', $sections[$i % $sectionCount]);
        }
        $created = $created->merge($leads);

        // Tier 3 — everybody else.
        //
        // THE FIRST LEAD CARRIES 34 AND THE REST SHARE WHAT IS LEFT, on any
        // department big enough for that to be true. Real trees are lopsided:
        // one unit lead runs the branch-operations floor and another runs a
        // four-person reconciliations desk, and a perfectly even tree would be
        // the giveaway that this estate is generated. It is also the demo — the
        // sentence on the broken-branch screen is "34 staff isolated", and that
        // number has to come from the shape of the tree rather than from a
        // fixture the analyser is handed.
        $remaining = max(0, $headcount - $created->count());
        $headline = $remaining >= self::HEADLINE_BRANCH * 2 ? self::HEADLINE_BRANCH : 0;

        for ($i = 0; $i < $remaining; $i++) {
            $lead = $i < $headline
                ? $leads[0]
                : $leads[$leadCount === 1 ? 0 : 1 + (($i - $headline) % max(1, $leadCount - 1))];

            $created->push($this->contact($organization, $unitId, $siteId, $label.' officer', $lead));
        }

        return $created;
    }

    private function contact(Organization $organization, ?int $unitId, ?int $siteId, string $title, ?Contact $manager): Contact
    {
        $this->sequence++;

        $name = self::FIRST_NAMES[$this->sequence % count(self::FIRST_NAMES)]
            .' '.self::LAST_NAMES[intdiv($this->sequence, count(self::FIRST_NAMES)) % count(self::LAST_NAMES)];

        // A `user_id` for the top of every chain, because a manager link points
        // at a USER — that is what a directory export gives you — and a chain
        // whose managers had no user rows would generate a flat tree.
        $user = $this->userFor($organization, $name, $this->sequence);

        return Contact::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'source' => ContactSource::Manual->value,
            'full_name' => $name,
            'employee_id' => 'KHB-D'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'business_unit_id' => $unitId,
            'site_id' => $siteId,
            'title' => $title,
            'manager_user_id' => $manager?->user_id,
            'email' => strtolower(str_replace(' ', '.', $name)).$this->sequence.'@kanoheritage.test',
            // Reserved range. Does not route.
            'mobile_primary' => '+2348000'.str_pad((string) (200 + $this->sequence), 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'consent_captured_at' => now()->subMonths(4),
            'verification_status' => 'verified',
            'last_verified_at' => now()->subDays(30 + ($this->sequence % 120)),
            'is_active' => true,
        ]);
    }

    /**
     * A shadow user for a contact.
     *
     * NOT A LOGIN. `is_active` false and no password: these rows exist so the
     * manager chain has something to point at, and a demo estate that created
     * three hundred people who could sign in would be a demo estate with three
     * hundred unmonitored accounts.
     */
    private function userFor(Organization $organization, string $name, int $sequence): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => 'roster'.$sequence.'@kanoheritage.test',
            'password' => bcrypt(str()->random(40)),
            'is_active' => false,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Trees */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $attributes */
    private function tree(Organization $organization, array $attributes): CallTree
    {
        $type = $attributes['tree_type'] instanceof CallTreeType ? $attributes['tree_type'] : CallTreeType::Department;
        $reviewedDaysAgo = (int) ($attributes['reviewed_days_ago'] ?? 30);
        $reviewedAt = Carbon::now()->subDays($reviewedDaysAgo);

        return CallTree::query()->create([
            'organization_id' => $organization->id,
            'business_unit_id' => $attributes['business_unit_id'] ?? null,
            'site_id' => $attributes['site_id'] ?? null,
            'name' => $attributes['name'],
            'tree_type' => $type->value,
            'version' => '1.0',
            'status' => CallTreeStatus::Approved->value,
            'approved_at' => $reviewedAt,
            'last_reviewed_at' => $reviewedAt,
            'review_frequency_days' => $type->defaultReviewDays(),
            'source' => ($attributes['source'] ?? CallTreeSource::Manual)->value,
            'iso_clause_ref' => 'ISO22301:8.4.3',
        ]);
    }

    /**
     * Lay a roster out as a cascade, following the manager chain it was built
     * with.
     *
     * @param  Collection<int, Contact>  $roster
     */
    private function build(CallTree $tree, Collection $roster): void
    {
        $byUser = $roster->filter(fn (Contact $c) => $c->user_id !== null)->keyBy('user_id');
        $nodeFor = [];
        $siblings = [];

        // Ordered so a manager is always inserted before their reports.
        $ordered = $roster->sortBy(fn (Contact $c) => $this->depthOf($c, $byUser))->values();

        foreach ($ordered as $contact) {
            $depth = $this->depthOf($contact, $byUser);
            $tier = min(3, $depth);
            $parentNodeId = null;

            if ($contact->manager_user_id !== null) {
                $manager = $byUser->get($contact->manager_user_id);
                $parentNodeId = $manager === null ? null : ($nodeFor[(int) $manager->getKey()] ?? null);
            }

            $node = CallTreeNode::query()->create([
                'organization_id' => $tree->organization_id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $parentNodeId,
                'tier' => $tier,
                'user_id' => $contact->user_id,
                'contact_id' => $contact->getKey(),
                'role_label' => $contact->title,
                'is_must_reach' => $tier <= 2,
                'expected_response_minutes' => $tier === 0 ? 10 : ($tier === 1 ? 15 : 30),
                'sequence' => count($siblings[$parentNodeId ?? 0] ?? []),
            ]);

            $siblings[$parentNodeId ?? 0][] = $node;
            $nodeFor[(int) $contact->getKey()] = (int) $node->getKey();

            // A deputy is the previous sibling — somebody who already does the
            // same job at the same level, which is who a real deputy is.
            $peers = $siblings[$parentNodeId ?? 0];
            if (count($peers) > 1) {
                $previous = $peers[count($peers) - 2];
                $node->update([
                    'deputy_contact_id' => $previous->contact_id,
                    'deputy_user_id' => $previous->user_id,
                ]);
            }
        }

        // The first person in each group had no earlier peer when they were
        // inserted. A second pass gives them the next one — otherwise every
        // parent's eldest child has no deputy, and a baseline with a hundred
        // gaps in it hides the five this estate is supposed to demonstrate.
        foreach ($siblings as $group) {
            if (count($group) > 1 && $group[0]->deputy_contact_id === null) {
                $group[0]->update([
                    'deputy_contact_id' => $group[1]->contact_id,
                    'deputy_user_id' => $group[1]->user_id,
                ]);
            }
        }

        $this->deputiseRoots($tree);
    }

    /**
     * A root has no peer, so its deputy is its first report — the acting head.
     *
     * The head of department is the single most important node on the tree and
     * the one likeliest to be on a plane. Leaving every root without a deputy
     * would put twenty-two entries on the health dashboard that are all the
     * same entry, and nobody works through a list like that.
     */
    private function deputiseRoots(CallTree $tree): void
    {
        foreach ($tree->nodes()->whereNull('parent_node_id')->get() as $root) {
            if ($root->deputy_contact_id !== null) {
                continue;
            }

            $second = $root->children()->orderBy('sequence')->orderBy('id')->first();

            if ($second !== null) {
                $root->update([
                    'deputy_contact_id' => $second->contact_id,
                    'deputy_user_id' => $second->user_id,
                ]);
            }
        }
    }

    /**
     * Deputies for a tree built by hand rather than from a roster: each node's
     * deputy is the next node at its own level.
     */
    private function deputiseSiblings(CallTree $tree): void
    {
        $groups = $tree->nodes()->orderBy('tier')->orderBy('sequence')->orderBy('id')->get()
            ->groupBy(fn (CallTreeNode $n) => (int) ($n->parent_node_id ?? 0));

        foreach ($groups as $group) {
            $rows = $group->values();

            foreach ($rows as $i => $node) {
                if ($node->deputy_contact_id !== null || $rows->count() < 2) {
                    continue;
                }

                $peer = $rows[($i + 1) % $rows->count()];
                $node->update([
                    'deputy_contact_id' => $peer->contact_id,
                    'deputy_user_id' => $peer->user_id,
                ]);
            }
        }

        $this->deputiseRoots($tree);
    }

    /** @param Collection<int, Contact> $byUser */
    private function depthOf(Contact $contact, Collection $byUser, int $guard = 0): int
    {
        if ($guard > 12 || $contact->manager_user_id === null) {
            return 0;
        }

        $manager = $byUser->get($contact->manager_user_id);

        return $manager === null ? 0 : 1 + $this->depthOf($manager, $byUser, $guard + 1);
    }

    private function seedCrisisTeam(Organization $organization, ?int $siteId): void
    {
        $tree = $this->tree($organization, [
            'site_id' => $siteId,
            'name' => 'Crisis Management Team',
            'tree_type' => CallTreeType::CrisisTeam,
            'reviewed_days_ago' => 20,
        ]);

        // Real users, not the shadow roster: the crisis team is the people who
        // actually hold the platform's BC roles.
        $contacts = Contact::query()->whereNotNull('user_id')
            ->where('employee_id', 'like', 'KHB-0%')->orderBy('id')->get();

        if ($contacts->isEmpty()) {
            return;
        }

        $root = null;
        foreach ($contacts as $index => $contact) {
            $node = CallTreeNode::query()->create([
                'organization_id' => $tree->organization_id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $index === 0 ? null : $root,
                'tier' => $index === 0 ? 0 : 1,
                'user_id' => $contact->user_id,
                'contact_id' => $contact->getKey(),
                'role_label' => $index === 0 ? 'Crisis Manager' : $contact->title,
                'is_must_reach' => true,
                'expected_response_minutes' => $index === 0 ? 10 : 15,
                'sequence' => $index,
            ]);

            if ($index === 0) {
                $root = (int) $node->getKey();
                $tree->update(['activation_authority_user_id' => $contact->user_id]);
            }
        }

        $this->deputiseSiblings($tree);
    }

    private function seedExecutive(Organization $organization, ?int $siteId): void
    {
        $tree = $this->tree($organization, [
            'site_id' => $siteId,
            'name' => 'Executive cascade',
            'tree_type' => CallTreeType::Executive,
            'reviewed_days_ago' => 15,
        ]);

        $md = $this->contact($organization, null, $siteId, 'Managing Director / CEO', null);
        $root = CallTreeNode::query()->create([
            'organization_id' => $tree->organization_id,
            'call_tree_id' => $tree->getKey(),
            'tier' => 0,
            'user_id' => $md->user_id,
            'contact_id' => $md->getKey(),
            'role_label' => 'Managing Director / CEO',
            'is_must_reach' => true,
            'expected_response_minutes' => 10,
        ]);

        foreach (['Executive Director, Operations', 'Executive Director, Risk', 'Chief Financial Officer'] as $i => $title) {
            $person = $this->contact($organization, null, $siteId, $title, $md);

            CallTreeNode::query()->create([
                'organization_id' => $tree->organization_id,
                'call_tree_id' => $tree->getKey(),
                'parent_node_id' => $root->getKey(),
                'tier' => 1,
                'user_id' => $person->user_id,
                'contact_id' => $person->getKey(),
                'role_label' => $title,
                'is_must_reach' => true,
                'expected_response_minutes' => 15,
                'sequence' => $i,
            ]);
        }

        $this->deputiseSiblings($tree);
    }

    /**
     * @param  Collection<string, int>|\Illuminate\Support\Collection<string, int>  $units
     * @param  \Illuminate\Support\Collection<string, int>  $sites
     */
    private function seedBranchTrees(Organization $organization, $units, $sites): void
    {
        $retailUnitId = $units['BU-RT'] ?? null;

        foreach ($sites as $code => $siteId) {
            if (! str_starts_with((string) $code, 'BR-')) {
                continue;
            }

            $site = Site::query()->find($siteId);

            $tree = $this->tree($organization, [
                'business_unit_id' => $retailUnitId,
                'site_id' => (int) $siteId,
                'name' => ($site === null ? (string) $code : $site->name).' call tree',
                'tree_type' => CallTreeType::Site,
                'reviewed_days_ago' => 45,
            ]);

            $manager = $this->contact($organization, $retailUnitId, (int) $siteId, 'Branch Manager', null);
            $root = CallTreeNode::query()->create([
                'organization_id' => $tree->organization_id,
                'call_tree_id' => $tree->getKey(),
                'tier' => 0,
                'user_id' => $manager->user_id,
                'contact_id' => $manager->getKey(),
                'role_label' => 'Branch Manager',
                'is_must_reach' => true,
                'expected_response_minutes' => 10,
            ]);

            $wardens = [];
            foreach (['Operations Officer', 'Floor Warden'] as $i => $title) {
                $person = $this->contact($organization, $retailUnitId, (int) $siteId, $title, $manager);
                $wardens[] = CallTreeNode::query()->create([
                    'organization_id' => $tree->organization_id,
                    'call_tree_id' => $tree->getKey(),
                    'parent_node_id' => $root->getKey(),
                    'tier' => 1,
                    'user_id' => $person->user_id,
                    'contact_id' => $person->getKey(),
                    'role_label' => $title,
                    'is_must_reach' => $i === 0,
                    'expected_response_minutes' => 15,
                    'sequence' => $i,
                ]);
            }

            foreach (range(0, 5) as $i) {
                $person = $this->contact($organization, $retailUnitId, (int) $siteId, 'Branch staff', $manager);
                CallTreeNode::query()->create([
                    'organization_id' => $tree->organization_id,
                    'call_tree_id' => $tree->getKey(),
                    'parent_node_id' => $wardens[$i % 2]->getKey(),
                    'tier' => 2,
                    'user_id' => $person->user_id,
                    'contact_id' => $person->getKey(),
                    'role_label' => 'Branch staff',
                    'expected_response_minutes' => 30,
                    'sequence' => $i,
                ]);
            }

            $this->deputiseSiblings($tree);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The deliberate flaws */
    /* ------------------------------------------------------------------ */

    /**
     * The demo. A Tier-2 unit lead with a wrong number, blocking the largest
     * downstream population on the estate.
     *
     * IT BREAKS THE CONTACT RECORD, NOT THE TEST. The failure has to happen
     * when the cascade runs, computed by the engine, or the screen is showing a
     * fixture. `mobile_primary` is nulled and `consecutive_failures` set, which
     * is exactly what a real dead record looks like — the tree still names
     * somebody and still looks complete, which is the whole problem.
     */
    private function breakTheOperationsTree(CallTree $tree): void
    {
        $target = CallTreeNode::query()
            ->where('call_tree_id', $tree->getKey())
            ->where('tier', 2)
            ->withCount('children')
            ->orderByDesc('children_count')
            ->first();

        if ($target === null) {
            return;
        }

        $contact = $target->contact;

        if ($contact === null) {
            return;
        }

        $contact->update([
            'mobile_primary' => null,
            'mobile_secondary' => null,
            'email' => null,
            'consecutive_failures' => 6,
            'verification_status' => 'failed',
            'last_verified_at' => null,
        ]);

        // No deputy, or the escalation would rescue the branch and there would
        // be nothing to demonstrate.
        $target->update([
            'deputy_contact_id' => null,
            'deputy_user_id' => null,
            'notes' => 'Contact details last confirmed in 2024. Flagged by the nightly hygiene sweep.',
        ]);
    }

    /**
     * Eight bad numbers, five must-reach nodes with no deputy, one withdrawal.
     */
    private function seedDataFlaws(): void
    {
        Contact::query()
            ->whereNotNull('mobile_primary')
            ->where('employee_id', 'like', 'KHB-D%')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->each(fn (Contact $c) => $c->update([
                'mobile_primary' => null,
                'consecutive_failures' => 4,
                'verification_status' => 'failed',
                'last_verified_at' => null,
            ]));

        CallTreeNode::query()
            ->where('is_must_reach', true)
            ->whereNotNull('deputy_contact_id')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->each(fn (CallTreeNode $n) => $n->update(['deputy_contact_id' => null, 'deputy_user_id' => null]));

        // One person who has exercised their NDPA right. The cascade must
        // exclude them visibly rather than silently, and the demo needs
        // somebody for that to happen to.
        $withdrawn = Contact::query()->where('employee_id', 'like', 'KHB-D%')->orderBy('id')->skip(3)->first();

        $withdrawn?->update([
            'consent_status' => 'withdrawn',
            'consent_withdrawn_at' => now()->subMonth(),
        ]);
    }
}
