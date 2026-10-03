<?php

namespace Database\Seeders\Bcms;

use App\Contracts\Bcms\DirectoryClient;
use App\Enums\Bcms\AutoApplyPolicy;
use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\SyncTrigger;
use App\Models\Bcms\IdentityConnector;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\TreeProposalService;
use App\Services\Bcms\Identity\DirectorySyncService;
use App\Services\Bcms\Identity\FakeDirectoryClient;
use App\Support\Bcms\DirectoryUser;
use Illuminate\Database\Seeder;

/**
 * BCMS Phase 2C demo estate — ADR 0018 §6, work order §9.
 *
 * RUNS A REAL SYNC AT SEED TIME, THROUGH THE FAKE CLIENT, exactly as
 * `CallTreeDemoSeeder` runs a real cascade rather than fixturing its result:
 * nothing here is inserted directly into `bcms_contacts` — every row comes
 * out of `DirectorySyncService::runFull()`, so a wrong number on the identity
 * screens means wrong code, not a mismatched fixture.
 *
 * TWO RUNS, NOT ONE. The 12 leavers and 8 movers are not present in the
 * FIRST directory read at all — they are introduced by REMOVING and
 * RE-DEPARTMENTING people between two full reconciliations, because a leaver
 * or a mover is, definitionally, a change against a roster that already
 * existed. The first run is the estate as it was; the second is the change
 * report this phase exists to produce.
 *
 * EVERY MOBILE NUMBER IS IN THE RESERVED `+2348000` RANGE AND DOES NOT
 * ROUTE, the same rule `CallTreeDemoSeeder` follows and for the same reason.
 */
class IdentityDemoSeeder extends Seeder
{
    /** Twelve Kano Heritage departments, headcounts summing to 200. */
    public const DEPARTMENTS = [
        ['BU-OP', 'Operations', 40],
        ['BU-IT', 'Information Technology', 20],
        ['BU-RT', 'Retail Banking', 25],
        ['BU-TR', 'Treasury', 15],
        ['BU-ERM', 'Enterprise Risk Management', 12],
        ['BU-CIC', 'Compliance', 10],
        ['BU-FC', 'Finance and Control', 14],
        ['BU-HR', 'Human Resources', 10],
        ['BU-IA', 'Internal Audit', 8],
        ['BU-LG', 'Legal', 6],
        ['BU-CX', 'Customer Experience', 20],
        ['BU-IB', 'Corporate Banking', 20],
    ];

    private const FIRST_NAMES = [
        'Ibrahim', 'Amina', 'Chidi', 'Ngozi', 'Musa', 'Folake', 'Emeka', 'Halima', 'Tunde', 'Aisha',
        'Segun', 'Zainab', 'Obinna', 'Yetunde', 'Sani', 'Blessing', 'Kabiru', 'Chioma', 'Bashir', 'Funke',
    ];

    private const LAST_NAMES = [
        'Sani', 'Okonkwo', 'Adeyemi', 'Bello', 'Eze', 'Abubakar', 'Nwosu', 'Lawal', 'Okafor', 'Yusuf',
    ];

    /**
     * Target size of each layer below the department head: deputies, unit
     * leads, team leads, then everyone else. Four entries, always — see
     * `buildDepartment()` for why that bounds the chain at exactly five
     * levels (the head plus these four).
     */
    private const LAYER_FANOUT = [2, 4, 8, 16];

    public function run(Organization $organization): void
    {
        if (IdentityConnector::query()->where('organization_id', $organization->id)->exists()) {
            return;
        }

        foreach (self::DEPARTMENTS as [$code, $name]) {
            BusinessUnit::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }

        $connector = IdentityConnector::query()->create([
            'organization_id' => $organization->id,
            'provider' => 'entra',
            'name' => 'Kano Heritage Bank — Entra ID',
            'directory_tenant_id' => 'demo-tenant',
            'client_id' => 'demo-client',
            'client_secret' => 'demo-secret',
            'token_base_url' => 'https://login.microsoftonline.com',
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_schedule' => 'nightly_plus_delta',
            // safe_only, so the demo shows BOTH an auto-applied joiner and a
            // pending, requires-ack leaver in the same run — the one sentence
            // Phase 6 proved is the demo, reachable from the identity screen
            // rather than only the call-tree one.
            'auto_apply_policy' => AutoApplyPolicy::SafeOnly->value,
            // INACTIVE, deliberately (ADR 0018 §2.2 point 5; gate 2 rejection
            // #3, blocking defect 3). This connector's `token_base_url` and
            // `graph_base_url` point at the REAL Microsoft hosts with a
            // `demo-client`/`demo-secret` that were never registered there —
            // seeded `is_active: true` on `nightly_plus_delta`,
            // `bcms:sync-directory --delta` (`SyncBcmsDirectory`) queued this
            // connector every fifteen minutes, each queue POSTing those fake
            // credentials to `login.microsoftonline.com` and each failure
            // waking `BcmsWatchdog`. A connector is created disabled until a
            // human runs "Test connection" — this seeder is not that human,
            // and the two `runFull()` calls below go straight through
            // `DirectorySyncService` rather than the command loop that reads
            // this flag, so the seeded run history is unaffected by it.
            'is_active' => false,
        ]);
        $connector->recordAudit('identity.connector.created', ['name' => $connector->name, 'seed' => true]);

        // THE FAKE IS BOUND BEFORE `DirectorySyncService` IS EVER RESOLVED.
        // `DirectorySyncService`'s constructor takes a `DirectoryClient` by
        // injection, so resolving the service FIRST bakes in whatever the
        // container was bound to at that moment — the real `EntraGraphClient`
        // — and swapping the binding afterwards changes nothing on the
        // already-built instance. Reversing the two lines made this seeder,
        // run as shipped, place a real HTTP call to Entra with the demo's
        // fake credentials the moment it ran.
        [$usersV1, $objectIds, $loopPairIds] = $this->buildDirectory(1);
        $fake = new FakeDirectoryClient($usersV1);
        app()->instance(DirectoryClient::class, $fake);

        $sync = app(DirectorySyncService::class);

        $sync->runFull($connector, SyncTrigger::Manual);

        // A demo call tree over Operations, approved, so the leaver we are
        // about to remove has somewhere to be a Tier-2 node.
        $operations = BusinessUnit::query()->where('organization_id', $organization->id)->where('code', 'BU-OP')->first();
        $victimObjectId = null;

        if ($operations !== null) {
            $proposal = app(TreeProposalService::class);
            $tree = $proposal->generate($operations, CallTreeType::Department, 'Operations call tree (demo)');
            app(CallTreeService::class)->approve($tree);

            $tier2 = $tree->nodes()->where('tier', 2)->whereNotNull('contact_id')->first();
            $victimObjectId = $tier2?->contact?->ad_object_guid;
        }

        // Twelve leavers: the Tier-2 victim plus eleven others, none of whom
        // are on the loop pair (a loop is already its own defect and must not
        // be conflated with a departure). Ten of the eleven are ABSENT from
        // the second read; the eleventh is still PRESENT but reported
        // `accountEnabled: false` — ADR 0018 §3.4's other leaver arm, so the
        // demo estate exercises both ways a leaver reaches the queue, not
        // only the absence one.
        $candidates = array_values(array_diff($objectIds, $loopPairIds, array_filter([$victimObjectId])));
        shuffle($candidates);
        $removedObjectIds = array_slice($candidates, 0, 10);
        $disabledObjectId = $candidates[10] ?? null;
        $leaverObjectIds = array_filter([$victimObjectId, ...$removedObjectIds]);

        [$usersV2] = $this->buildDirectory(
            2,
            removeObjectIds: $leaverObjectIds,
            moveCount: 8,
            disableObjectIds: array_filter([$disabledObjectId]),
        );
        $fake->setUsers($usersV2);

        $run2 = $sync->runFull($connector, SyncTrigger::Manual);

        $run2->recordAudit('identity.sync.finished', ['seed' => true, 'note' => 'demo second run — leavers and movers']);
    }

    /**
     * Public so `Phase2cIdentitySyncTest` can build the identical fixture
     * this seeder runs a real sync against, rather than a smaller
     * hand-rolled stand-in that would not actually exercise the seeder's own
     * code (development standard §10: pin a characterisation test against
     * the running thing).
     *
     * @param  list<string>  $removeObjectIds
     * @param  list<string>  $disableObjectIds  present in this generation's
     *                                          read, same as ever, but
     *                                          reported with `accountEnabled:
     *                                          false` — the other leaver arm
     *                                          (ADR 0018 §3.4), alongside
     *                                          `$removeObjectIds`'s absence
     *                                          one.
     * @return array{0: list<DirectoryUser>, 1: list<string>, 2: list<string>}
     */
    public function buildDirectory(int $generation, array $removeObjectIds = [], int $moveCount = 0, array $disableObjectIds = []): array
    {
        $users = [];
        $objectIds = [];
        $sequence = 0;
        $deptCodes = array_column(self::DEPARTMENTS, 0);

        foreach (self::DEPARTMENTS as [$code, $name, $headcount]) {
            [$deptUsers, $sequence] = $this->buildDepartment($code, $headcount, $sequence, $generation);
            array_push($users, ...$deptUsers);

            foreach ($deptUsers as $u) {
                $objectIds[] = $u->objectId;
            }
        }

        // One deliberate management loop: two people in the same department
        // pointed at each other, isolated from the rest of that department's
        // chain. `TreeProposalService::arrange()` must report them unplaced
        // and must not hang.
        $loopPairIds = [];

        if (count($users) >= 2) {
            $a = $users[count($users) - 1];
            $b = $users[count($users) - 2];
            $loopA = new DirectoryUser($a->objectId, $a->userPrincipalName, $a->displayName, $a->mail, $a->mobilePhone, $a->businessPhone, $a->jobTitle, $a->department, $a->officeLocation, $a->employeeId, true, $b->objectId);
            $loopB = new DirectoryUser($b->objectId, $b->userPrincipalName, $b->displayName, $b->mail, $b->mobilePhone, $b->businessPhone, $b->jobTitle, $b->department, $b->officeLocation, $b->employeeId, true, $a->objectId);
            $users[count($users) - 1] = $loopA;
            $users[count($users) - 2] = $loopB;
            $loopPairIds = [$a->objectId, $b->objectId];
        }

        if ($removeObjectIds !== []) {
            $remove = array_flip($removeObjectIds);
            $users = array_values(array_filter($users, fn (DirectoryUser $u) => ! isset($remove[$u->objectId])));
        }

        if ($moveCount > 0) {
            $eligible = array_slice($users, 0, min($moveCount, count($users)));

            foreach ($eligible as $index => $u) {
                $newDept = $deptCodes[($index + 1) % count($deptCodes)];
                $users[$index] = new DirectoryUser($u->objectId, $u->userPrincipalName, $u->displayName, $u->mail, $u->mobilePhone, $u->businessPhone, $u->jobTitle, $newDept, $u->officeLocation, $u->employeeId, true, $u->managerObjectId);
            }
        }

        if ($disableObjectIds !== []) {
            $disable = array_flip($disableObjectIds);

            foreach ($users as $index => $u) {
                if (! isset($disable[$u->objectId])) {
                    continue;
                }

                $users[$index] = new DirectoryUser($u->objectId, $u->userPrincipalName, $u->displayName, $u->mail, $u->mobilePhone, $u->businessPhone, $u->jobTitle, $u->department, $u->officeLocation, $u->employeeId, false, $u->managerObjectId);
            }
        }

        return [$users, $objectIds, $loopPairIds];
    }

    /**
     * A department's roster as fixed LAYERS — head, then up to four more
     * layers of decreasing seniority — rather than a randomly-widening span.
     *
     * DEPTH IS BOUNDED AT EXACTLY FOUR LAYERS BELOW THE HEAD (five levels,
     * 0..4), never more, by construction: the loop below only ever builds
     * `count(self::LAYER_FANOUT)` layers, and whatever headcount is left
     * after the last of them is placed lands IN that last layer rather than
     * starting a sixth. That is what makes "a 5-level manager chain" a fact
     * about every department big enough to reach it, not a coincidence of
     * how far a widening pool happened to get.
     *
     * DETERMINISTIC, NOT RANDOM — this runs twice, once per generation, and
     * the shape must be identical both times for anyone who is not one of
     * the seeder's own deliberate leavers or movers, or a reshuffle on the
     * second build would manufacture manager changes nobody asked for.
     *
     * @return array{0: list<DirectoryUser>, 1: int} the department's users, and the next global sequence
     */
    private function buildDepartment(string $code, int $headcount, int $sequence, int $generation): array
    {
        $head = $this->user($code, 0, null, $sequence++, $generation);
        $users = [$head];
        $previousLayer = [$head];
        $created = 1;

        foreach (self::LAYER_FANOUT as $layerIndex => $targetSize) {
            if ($created >= $headcount) {
                break;
            }

            $isLastLayer = $layerIndex === count(self::LAYER_FANOUT) - 1;
            $remaining = $headcount - $created;
            // The last layer absorbs everyone left, so depth never exceeds
            // this fixed number of layers regardless of headcount.
            $thisLayerSize = $isLastLayer ? $remaining : min($targetSize, $remaining);

            $thisLayer = [];

            for ($i = 0; $i < $thisLayerSize; $i++) {
                $parent = $previousLayer[$i % count($previousLayer)];
                $u = $this->user($code, min($layerIndex + 1, 4), $parent->objectId, $sequence++, $generation);
                $users[] = $u;
                $thisLayer[] = $u;
                $created++;
            }

            $previousLayer = $thisLayer;
        }

        // Headcounts larger than the fixed layers can absorb (none in
        // practice, given the 12 departments' actual counts) fall in under
        // the deepest layer built rather than starting a new one.
        while ($created < $headcount) {
            $parent = $previousLayer[$created % max(1, count($previousLayer))];
            $u = $this->user($code, 4, $parent->objectId, $sequence++, $generation);
            $users[] = $u;
            $created++;
        }

        return [$users, $sequence];
    }

    private function user(string $deptCode, int $tier, ?string $managerObjectId, int $sequence, int $generation): DirectoryUser
    {
        $first = self::FIRST_NAMES[$sequence % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[($sequence + $generation) % count(self::LAST_NAMES)];
        $name = "{$first} {$last}";
        $objectId = "{$deptCode}-{$sequence}";

        // ~30% missing mobiles — a data-hygiene reality, not a fixture flaw.
        $hasMobile = $sequence % 10 >= 3;

        return new DirectoryUser(
            objectId: $objectId,
            userPrincipalName: strtolower($first.'.'.$last.$sequence).'@khb-demo.test',
            displayName: $name,
            mail: strtolower($first.'.'.$last.$sequence).'@khb-demo.test',
            mobilePhone: $hasMobile ? sprintf('+2348000%06d', $sequence) : null,
            businessPhone: null,
            jobTitle: match ($tier) {
                0 => 'Head of Department',
                1 => 'Deputy Head',
                2 => 'Unit Lead',
                3 => 'Team Lead',
                default => 'Officer',
            },
            department: $deptCode,
            officeLocation: null,
            employeeId: 'EMP-'.$objectId,
            accountEnabled: true,
            managerObjectId: $managerObjectId,
        );
    }
}
