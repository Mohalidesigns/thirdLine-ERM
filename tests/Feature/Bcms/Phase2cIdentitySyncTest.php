<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DirectoryClient;
use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\SyncChangeDecision;
use App\Enums\Bcms\SyncChangeKind;
use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Exceptions\Bcms\DirectorySyncException;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\TreeProposalService;
use App\Services\Bcms\Identity\ChangeApplier;
use App\Services\Bcms\Identity\ChangeDetector;
use App\Services\Bcms\Identity\DirectorySyncService;
use App\Services\Bcms\Identity\EntraGraphClient;
use App\Services\Bcms\Identity\FakeDirectoryClient;
use App\Support\Bcms\DirectoryAttributeMap;
use App\Support\Bcms\DirectoryUser;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\IdentityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 2C (reduced) — the nine acceptance criteria of ADR 0018 §6, in
 * order.
 *
 * `Http::preventStrayRequests()` IS IN FORCE THROUGHOUT (ADR 0018 §4, §6
 * criterion 8). No test in this file reaches the network — the bulk of it
 * runs against `FakeDirectoryClient`, and the two that exercise
 * `EntraGraphClient` do so only against `Http::fake()`.
 */
class Phase2cIdentitySyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // `EntraGraphClient` honours `Retry-After` on a 429 and backs off on a
        // 5xx (a capped ladder — see `Phase2cRuntimeTest`). Faking Sleep keeps
        // the criterion 8 cases proving the CLASSIFICATION they exist for
        // without the suite waiting sixty real seconds for the retries.
        Sleep::fake();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        foreach (IdentityDemoSeeder::DEPARTMENTS as [$code, $name]) {
            BusinessUnit::query()->create([
                'organization_id' => $this->organization->id, 'code' => $code, 'name' => $name, 'is_active' => true,
            ]);
        }

        $this->admin = User::create([
            'organization_id' => $this->organization->id, 'name' => 'BC Coordinator',
            'email' => 'bc-coordinator@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Helpers */
    /* ================================================================== */

    private function connector(array $overrides = []): IdentityConnector
    {
        return IdentityConnector::query()->create(array_merge([
            'organization_id' => $this->organization->id,
            'provider' => 'entra',
            'name' => 'Kano Heritage Bank — Entra ID',
            'directory_tenant_id' => 'test-tenant',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'token_base_url' => 'https://login.microsoftonline.com',
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_schedule' => 'nightly',
            'auto_apply_policy' => 'safe_only',
            'is_active' => true,
        ], $overrides));
    }

    private function swapDirectoryClient(FakeDirectoryClient $fake): FakeDirectoryClient
    {
        app()->instance(DirectoryClient::class, $fake);

        return $fake;
    }

    private function service(): DirectorySyncService
    {
        return app(DirectorySyncService::class);
    }

    /* ================================================================== */
    /*  Criterion 1 — 200 users, 12 departments, 5-level chain, paging. */
    /* ================================================================== */

    #[Test]
    public function criterion_1_a_full_reconciliation_of_two_hundred_users_pages_and_counts_correctly(): void
    {
        $connector = $this->connector();
        [$users] = (new IdentityDemoSeeder)->buildDirectory(1);
        $this->assertCount(200, $users, 'The fixture itself must be ~200 users across the 12 departments.');

        $fake = $this->swapDirectoryClient((new FakeDirectoryClient($users))->withPageSize(37));

        $run = $this->service()->runFull($connector, SyncTrigger::Manual, $this->admin->id);

        $this->assertSame(SyncRunStatus::Success, $run->status);
        $this->assertSame(200, $run->directory_objects_read);
        $this->assertGreaterThan(1, $run->pages_fetched, 'Paging must be exercised, not simulated as one page.');
        $this->assertSame(200, $run->joiner_count);
        $this->assertSame(0, $run->leaver_count);
        $this->assertSame(0, $run->mover_count);
        $this->assertSame(200, Contact::query()->where('organization_id', $this->organization->id)->count());
    }

    /**
     * Gate 2 advisories 5/6: `ChangeDetector::preloadRoster()` builds its
     * business-unit, site and existing-contact lookups ONCE per run rather
     * than re-querying per directory user. Called in isolation from staging
     * (no `ChangeApplier` writes in the query count) so this test measures
     * detection alone — the O(n) pattern this guards against would pass on
     * a five-user fixture and only surface against a real department's
     * headcount, which is exactly why the fixture here is the full 200.
     */
    #[Test]
    public function detection_over_two_hundred_users_issues_a_bounded_number_of_queries_not_one_per_user(): void
    {
        $connector = $this->connector();
        [$users] = (new IdentityDemoSeeder)->buildDirectory(1);
        $this->assertCount(200, $users);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = (new ChangeDetector)->detectFullReconciliation($connector, $users);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(200, $result['changes'], 'Every one of the 200 directory users must still be detected as a joiner.');
        $this->assertLessThan(
            150,
            $queryCount,
            sprintf(
                'Detecting 200 directory users issued %d queries — the roster must be preloaded once (three queries), not per user.',
                $queryCount,
            ),
        );
    }

    /**
     * Gate 2 advisory 3 — `ChangeDetector::preloadRoster()` narrows its
     * `bcms_contacts` read to the columns detection actually touches,
     * deliberately excluding every `ChangeApplier::NEVER_WRITE` column
     * (`next_of_kin`, `whatsapp`, ...) from the SELECT and from memory for
     * the whole run. Proven two ways: the raw SQL captured on the query log
     * names none of them, AND a change this narrowed instance produces still
     * carries everything `ChangeApplier` needs to actually apply it —
     * because the applier re-fetches its own full-column `Contact` (via
     * `IdentitySyncChange::contact()`) rather than reusing the detector's
     * narrowed one, so nothing is silently missing downstream.
     */
    #[Test]
    public function preload_excludes_never_write_columns_and_the_applier_still_has_what_it_needs(): void
    {
        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $enabled = new DirectoryUser('HEAD-NEVERWRITE-1', 'headnw1@khb.test', 'Never Write Head', 'headnw1@khb.test', '+2348000000015', null, 'Head', 'BU-OP', null, 'ENW1', true, null);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$enabled]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-NEVERWRITE-1')->firstOrFail();
        $contact->forceFill([
            'next_of_kin' => ['name' => 'Someone Else', 'phone' => '+2348099999999'],
            'whatsapp' => '+2348099999999',
            'consent_status' => 'granted',
        ])->save();

        $drifted = new DirectoryUser('HEAD-NEVERWRITE-1', 'headnw1@khb.test', 'Never Write Head', 'headnw1@khb.test', '+2348000000015', null, 'A New Title', 'BU-OP', null, 'ENW1', true, null);
        $fake->setUsers([$drifted]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $contactsQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'select') && str_contains($q['query'], 'bcms_contacts') && ! str_contains($q['query'], 'count('),
        );
        DB::disableQueryLog();

        $this->assertNotEmpty($contactsQueries, 'The preload must still query bcms_contacts at least once.');

        foreach (ChangeApplier::NEVER_WRITE as $neverWriteColumn) {
            foreach ($contactsQueries as $q) {
                $this->assertStringNotContainsString(
                    "`{$neverWriteColumn}`",
                    $q['query'],
                    "preloadRoster()'s SELECT must never name `{$neverWriteColumn}` — a never-write column has no business in the detector's memory.",
                );
            }
        }

        // The applier still had everything it needed: the title change was
        // detected and applied, and the never-write columns survive
        // untouched (proving they were never part of the write set either).
        $this->assertSame(1, $run2->contact_change_count);
        $contact->refresh();
        $this->assertSame('A New Title', $contact->title);
        $this->assertSame('granted', $contact->consent_status->value, 'A never-write column must survive a sync completely untouched.');
    }

    /* ================================================================== */
    /*  Criteria 2 & 3 — leavers/movers, requires_ack, the 5-level tree, */
    /*  contacts with no user_id, and re-parenting on the next run. */
    /* ================================================================== */

    #[Test]
    public function criteria_2_and_3_leavers_movers_and_the_five_level_tree_from_manager_contact_id(): void
    {
        $connector = $this->connector();
        $seeder = new IdentityDemoSeeder;
        [$usersV1, $objectIds, $loopIds] = $seeder->buildDirectory(1);

        $fake = $this->swapDirectoryClient(new FakeDirectoryClient($usersV1));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $operations = BusinessUnit::query()
            ->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->first();
        $this->assertNotNull($operations);

        // The proposal is built entirely from `manager_contact_id` — none of
        // these 40 contacts has a `user_id` at all, which is the case ADR
        // 0018 says was impossible before this phase.
        $this->assertSame(0, Contact::query()->where('business_unit_id', $operations->id)->whereNotNull('user_id')->count());

        $plan = app(TreeProposalService::class)->preview($operations);
        $this->assertSame(40, $plan['total']);
        $this->assertSame(5, $plan['depth'], 'A 5-level manager chain must produce a depth of 5.');
        $this->assertSame([], $plan['unplaced'], 'Operations has no management loop in this fixture.');

        $tree = app(TreeProposalService::class)->generate($operations, CallTreeType::Department, 'Operations (Phase 2C test)');
        app(CallTreeService::class)->approve($tree);

        $victimNode = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 2)->whereNotNull('contact_id')->first();
        $this->assertNotNull($victimNode, 'The fixture must produce at least one Tier-2 node to be the victim.');
        $victimObjectId = $victimNode->contact->ad_object_guid;
        $expectedDownstream = app(CallTreeService::class)->downstreamCount($victimNode);

        // The "other 11" leavers are drawn from OUTSIDE Operations — every
        // Operations contact is a node on the approved tree, and picking one
        // at random would sometimes carry its own real tree impact, making
        // this assertion flaky for a reason that has nothing to do with the
        // one victim criterion 2 is about.
        $candidates = array_values(array_filter(
            array_diff($objectIds, $loopIds, [$victimObjectId]),
            fn (string $id) => ! str_starts_with($id, 'BU-OP-'),
        ));
        shuffle($candidates);
        $leaverIds = array_values(array_filter(array_merge([$victimObjectId], array_slice($candidates, 0, 11))));
        $this->assertCount(12, array_unique($leaverIds));

        [$usersV2] = $seeder->buildDirectory(2, removeObjectIds: $leaverIds, moveCount: 8);
        $fake->setUsers($usersV2);

        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(12, $run2->leaver_count, 'Twelve leavers must be detected.');
        $this->assertSame(8, $run2->mover_count, 'Eight movers must be detected.');

        $victimChange = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())
            ->where('contact_id', $victimNode->contact_id)
            ->where('kind', 'leaver')
            ->first();

        $this->assertNotNull($victimChange);
        $this->assertTrue($victimChange->requires_ack, 'A Tier-2 node on an approved tree must require acknowledgement.');
        $this->assertSame(SyncChangeDecision::Pending, $victimChange->decision, 'It must not auto-apply.');
        $this->assertSame($expectedDownstream, $victimChange->impact_json['call_trees'][0]['downstream_blocked_count'] ?? null);
        $this->assertSame((int) $victimNode->tier, $victimChange->impact_json['call_trees'][0]['tier'] ?? null);

        // The other 11 leavers carried no tree impact and were auto-applied
        // (`safe_only`) without acknowledgement.
        $otherLeavers = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')
            ->where('id', '!=', $victimChange->getKey())->get();
        $this->assertCount(11, $otherLeavers);
        $this->assertTrue($otherLeavers->every(fn (IdentitySyncChange $c) => ! $c->requires_ack));

        // The victim is still active until acknowledged.
        $victimNode->contact->refresh();
        $this->assertTrue((bool) $victimNode->contact->is_active);

        $this->service()->decide($victimChange, SyncChangeDecision::Approved, $this->admin->id);
        $victimNode->contact->refresh();
        $this->assertFalse((bool) $victimNode->contact->is_active, 'Once acknowledged, the leaver applies.');

        // Movers re-parent correctly: their business_unit_id now matches the
        // department the second directory read assigned them to.
        $moverChanges = IdentitySyncChange::query()->where('sync_run_id', $run2->getKey())->where('kind', 'mover')->get();
        $this->assertCount(8, $moverChanges);

        foreach ($moverChanges as $change) {
            if ($change->decision === SyncChangeDecision::Pending) {
                $this->service()->decide($change, SyncChangeDecision::Approved, $this->admin->id);
            }

            $contact = Contact::query()->find($change->contact_id);
            $this->assertNotNull($contact);

            if (isset($change->after_json['business_unit_id'])) {
                $this->assertSame(
                    $change->after_json['business_unit_id'],
                    $contact->business_unit_id,
                    'A mover re-parents into the department the directory now assigns them to.',
                );
            }
        }
    }

    /* ================================================================== */
    /*  Criterion 4 — no write scope, no write call. */
    /* ================================================================== */

    #[Test]
    public function criterion_4_the_token_request_asks_for_user_read_all_and_nothing_writes(): void
    {
        $connector = $this->connector([
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => $this->fakeJwt(['User.Read.All']),
                'expires_in' => 3600,
            ], 200),
            'https://graph.microsoft.com/*' => Http::response(['value' => [], '@odata.nextLink' => null], 200),
        ]);

        $result = app(EntraGraphClient::class)->testConnection($connector);

        $this->assertTrue($result['ok']);
        $this->assertSame(['User.Read.All'], $result['scopes']);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'login.microsoftonline.com')
            && $request->method() === 'POST'
            && str_contains((string) $request->body(), 'grant_type=client_credentials')
            && str_contains((string) $request->body(), '.default'));

        // `assertSent` only proves ONE matching request exists — the real
        // guarantee is that NONE of the requests to the Graph host are
        // anything but a GET, checked over every request this test recorded.
        foreach (Http::recorded() as $pair) {
            /** @var \Illuminate\Http\Client\Request $request */
            $request = $pair[0];

            if (str_contains($request->url(), 'graph.microsoft.com')) {
                $this->assertSame('GET', $request->method(), 'Only GET requests may reach a Graph host.');
            }
        }
    }

    private function fakeJwt(array $roles): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['roles' => $roles])), '+/', '-_'), '=');

        return "{$header}.{$payload}.signature";
    }

    /* ================================================================== */
    /*  Criterion 5 — a human-edited value is never overwritten. */
    /* ================================================================== */

    #[Test]
    public function criterion_5_a_human_edited_value_raises_a_contact_change_and_is_not_overwritten(): void
    {
        $manager = new DirectoryUser('MGR-1', 'mgr1@khb.test', 'Manager One', 'mgr1@khb.test', '+2348000000001', null, 'Head', 'BU-OP', null, 'E1', true, null);
        $report = new DirectoryUser('REP-1', 'rep1@khb.test', 'Report One', 'rep1@khb.test', '+2348000000002', null, 'Officer', 'BU-OP', null, 'E2', true, 'MGR-1');

        $connector = $this->connector();
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$manager, $report]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'REP-1')->firstOrFail();

        // A human edits the mobile number, the WhatsApp handle, the language
        // and the consent status directly.
        $contact->forceFill([
            'mobile_primary' => '+2348099999999',
            'whatsapp' => '+2348099999999',
            'preferred_language' => 'ha',
            'consent_status' => 'granted',
            'consent_captured_at' => now(),
        ])->save();

        // The directory now reports a DIFFERENT mobile number for the same person.
        $reportV2 = new DirectoryUser('REP-1', 'rep1@khb.test', 'Report One', 'rep1@khb.test', '+2348000009999', null, 'Officer', 'BU-OP', null, 'E2', true, 'MGR-1');
        $fake->setUsers([$manager, $reportV2]);

        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $change = IdentitySyncChange::query()->where('sync_run_id', $run2->getKey())->where('contact_id', $contact->getKey())->first();
        $this->assertNotNull($change, 'A contact_change must be raised even though the field will not be overwritten.');

        $contact->refresh();
        $this->assertSame('+2348099999999', $contact->mobile_primary, 'The human-edited mobile number survives the sync.');
        $this->assertSame('+2348099999999', $contact->whatsapp, 'whatsapp is never synced at all.');
        $this->assertSame('ha', $contact->preferred_language, 'preferred_language is never synced at all.');
        $this->assertSame('granted', $contact->consent_status->value, 'consent_status is never synced at all.');
    }

    /* ================================================================== */
    /*  Criterion 6 — a leaver is deactivated, never deleted. */
    /* ================================================================== */

    #[Test]
    public function criterion_6_a_leaver_is_deactivated_never_deleted_and_stays_resolvable(): void
    {
        $unit = BusinessUnit::query()->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->first();
        $head = new DirectoryUser('HEAD-1', 'head1@khb.test', 'Department Head', 'head1@khb.test', '+2348000000010', null, 'Head', 'BU-OP', null, 'EH1', true, null);

        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$head]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-1')->firstOrFail();

        // `generate()` already creates one node for the department's sole
        // contact — no need to hand-build a second one.
        $tree = app(TreeProposalService::class)->generate($unit, CallTreeType::Department);
        $node = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('contact_id', $contact->getKey())->firstOrFail();

        $fake->setUsers([]);
        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(1, $run2->leaver_count);
        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active, 'A leaver is deactivated.');
        $this->assertNotNull(Contact::query()->find($contact->getKey()), 'The row is never deleted.');

        $node->refresh();
        $this->assertNotNull($node->contact, 'The call-tree node still resolves the (now inactive) contact.');
    }

    /**
     * Criterion 6's OTHER leaver arm (ADR 0018 §3.4): the account is never
     * absent from the read at all — it stays present, on every run, with
     * `accountEnabled` flipped to `false`. A full reconciliation must treat
     * that exactly like the absence above: staged as a `leaver`, routed
     * through `ImpactAssessor` (so a call-tree node still requires
     * acknowledgement), deactivated-never-deleted once applied, and able to
     * reactivate when the account is re-enabled.
     */
    #[Test]
    public function criterion_6b_an_entra_account_disabled_in_place_deactivates_the_contact_and_reactivates_on_re_enable(): void
    {
        $unit = BusinessUnit::query()->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->first();
        $enabled = new DirectoryUser('HEAD-DISABLE-1', 'headd1@khb.test', 'Disable Head', 'headd1@khb.test', '+2348000000011', null, 'Head', 'BU-OP', null, 'EHD1', true, null);

        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$enabled]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-DISABLE-1')->firstOrFail();

        $tree = app(TreeProposalService::class)->generate($unit, CallTreeType::Department);
        app(CallTreeService::class)->approve($tree);
        $node = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('contact_id', $contact->getKey())->firstOrFail();

        // Still PRESENT on the second read — the same object, the same
        // attributes — only `accountEnabled` differs.
        $disabled = new DirectoryUser('HEAD-DISABLE-1', 'headd1@khb.test', 'Disable Head', 'headd1@khb.test', '+2348000000011', null, 'Head', 'BU-OP', null, 'EHD1', false, null);
        $fake->setUsers([$disabled]);
        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(1, $run2->leaver_count, 'A disabled-in-place Entra account must be counted as a leaver on a full reconciliation.');

        $change = IdentitySyncChange::query()->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')->firstOrFail();
        $this->assertTrue($change->requires_ack, 'A call-tree node must require acknowledgement, same as an absence-based leaver.');
        $this->assertSame(SyncChangeDecision::Pending, $change->decision, 'It must not auto-apply.');

        $contact->refresh();
        $this->assertTrue((bool) $contact->is_active, 'Must stay active until the requires_ack row is acknowledged.');

        $this->service()->decide($change, SyncChangeDecision::Approved, $this->admin->id);
        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active, 'Once acknowledged, a disabled account deactivates the contact.');
        $this->assertNotNull(Contact::query()->find($contact->getKey()), 'The row is never deleted.');

        $node->refresh();
        $this->assertNotNull($node->contact, 'The call-tree node still resolves the (now inactive) contact.');

        // Re-enabled in Entra on the next full run: the contact reactivates
        // without a second acknowledgement — a `contact_change`, not a
        // `leaver`, so `safe_only` auto-applies it.
        $reenabled = new DirectoryUser('HEAD-DISABLE-1', 'headd1@khb.test', 'Disable Head', 'headd1@khb.test', '+2348000000011', null, 'Head', 'BU-OP', null, 'EHD1', true, null);
        $fake->setUsers([$reenabled]);
        $run3 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(0, $run3->leaver_count);
        $contact->refresh();
        $this->assertTrue((bool) $contact->is_active, 'Re-enabling the Entra account reactivates the contact.');
    }

    /**
     * A disabled-in-place leaver (criterion 6b) that is never acknowledged
     * and then vanishes from the directory entirely on a later run must not
     * leave two live pending leaver rows for the same contact — the second
     * full reconciliation's absence-based leaver supersedes the first's
     * disabled-based one, exactly as `DirectorySyncService::stageAndApply()`
     * already does for any repeated `(directory_object_id, kind)` pair
     * (ADR 0018 §2.4), so the object never double-stages across the two
     * different leaver signals.
     */
    #[Test]
    public function a_disabled_account_absent_from_a_later_full_run_does_not_double_stage(): void
    {
        $enabled = new DirectoryUser('HEAD-DISABLE-2', 'headd2@khb.test', 'Disable Head Two', 'headd2@khb.test', '+2348000000012', null, 'Head', 'BU-OP', null, 'EHD2', true, null);

        // `none`, so the run-2 disabled leaver stays pending rather than
        // auto-applying — it needs to still be pending when run 3 arrives
        // for the supersession to have anything to supersede.
        $connector = $this->connector(['auto_apply_policy' => 'none']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$enabled]));
        $run1 = $this->service()->runFull($connector, SyncTrigger::Manual);

        // `none` leaves the joiner itself pending too — approve it so the
        // contact actually exists before run 2's disabled-in-place read
        // needs to match against it.
        $joinerChange = IdentitySyncChange::query()->where('sync_run_id', $run1->getKey())->where('kind', 'joiner')->firstOrFail();
        $this->service()->decide($joinerChange, SyncChangeDecision::Approved, $this->admin->id);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-DISABLE-2')->firstOrFail();

        $disabled = new DirectoryUser('HEAD-DISABLE-2', 'headd2@khb.test', 'Disable Head Two', 'headd2@khb.test', '+2348000000012', null, 'Head', 'BU-OP', null, 'EHD2', false, null);
        $fake->setUsers([$disabled]);
        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $disabledLeaverChange = IdentitySyncChange::query()->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')->firstOrFail();
        $this->assertSame(SyncChangeDecision::Pending, $disabledLeaverChange->decision);

        $contact->refresh();
        $this->assertTrue((bool) $contact->is_active, 'Still active — the disabled leaver from run 2 was never acknowledged.');

        // Absent entirely on run 3 — the object is gone from the directory,
        // not merely disabled. The contact is still active (nothing applied
        // run 2's leaver), so the leaver sweep detects it again.
        $fake->setUsers([]);
        $run3 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(1, $run3->leaver_count, 'Exactly one leaver — the same object, not staged twice within run 3 itself.');

        $disabledLeaverChange->refresh();
        $this->assertSame(
            SyncChangeDecision::Superseded,
            $disabledLeaverChange->decision,
            'The run-2 disabled-leaver row must be superseded by run 3, not left pending alongside a second one.',
        );

        $pendingLeaverRows = IdentitySyncChange::query()
            ->where('contact_id', $contact->getKey())
            ->where('kind', 'leaver')
            ->where('decision', SyncChangeDecision::Pending->value)
            ->get();

        $this->assertCount(1, $pendingLeaverRows, 'Only one pending leaver row may exist for this contact at a time.');
        $this->assertSame($run3->getKey(), $pendingLeaverRows->first()->sync_run_id);
    }

    /**
     * Gate 2 advisory 5. An already-deactivated contact whose Entra object is
     * STILL PRESENT and STILL disabled must stage nothing at all on a later
     * full reconciliation, even when Graph reports a drifted title or
     * department for it — a departed person's record is not worth reviewing
     * again for an attribute nobody can act on.
     */
    #[Test]
    public function an_already_inactive_and_still_disabled_account_stages_no_attribute_drift(): void
    {
        $enabled = new DirectoryUser('HEAD-DISABLE-3', 'headd3@khb.test', 'Disable Head Three', 'headd3@khb.test', '+2348000000014', null, 'Head', 'BU-OP', null, 'EHD3', true, null);

        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$enabled]));
        $run1 = $this->service()->runFull($connector, SyncTrigger::Manual);
        $this->assertSame(1, $run1->joiner_count);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-DISABLE-3')->firstOrFail();

        $disabled = new DirectoryUser('HEAD-DISABLE-3', 'headd3@khb.test', 'Disable Head Three', 'headd3@khb.test', '+2348000000014', null, 'Head', 'BU-OP', null, 'EHD3', false, null);
        $fake->setUsers([$disabled]);
        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);
        $this->assertSame(1, $run2->leaver_count);

        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active, 'safe_only auto-applies a leaver with no call-tree impact.');

        // Still present, still disabled, but now with a drifted title AND
        // department — the kind of change that would ordinarily stage a
        // `mover` or `contact_change`.
        $stillDisabledWithDrift = new DirectoryUser('HEAD-DISABLE-3', 'headd3@khb.test', 'Disable Head Three', 'headd3@khb.test', '+2348000000014', null, 'A Different Title', 'BU-IT', null, 'EHD3', false, null);
        $fake->setUsers([$stillDisabledWithDrift]);
        $run3 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(0, $run3->leaver_count);
        $this->assertSame(0, $run3->mover_count);
        $this->assertSame(0, $run3->contact_change_count);
        $this->assertSame(
            0,
            IdentitySyncChange::query()->where('sync_run_id', $run3->getKey())->count(),
            'Nothing at all should be staged for an already-inactive, still-disabled account.',
        );

        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active);
        $this->assertSame('Head', $contact->title, 'The drifted title must never be written — nothing was staged, let alone applied.');
    }

    /* ================================================================== */
    /*  Criterion 7 — a management loop is reported, never hangs. */
    /* ================================================================== */

    #[Test]
    public function criterion_7_a_management_loop_is_reported_as_unplaced_and_does_not_hang(): void
    {
        $connector = $this->connector();
        [$users] = (new IdentityDemoSeeder)->buildDirectory(1);
        $this->swapDirectoryClient(new FakeDirectoryClient($users));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        // The seeder places its one deliberate loop in the LAST department
        // processed (BU-IB, Corporate Banking).
        $corporateBanking = BusinessUnit::query()
            ->where('organization_id', $this->organization->id)->where('code', 'BU-IB')->first();

        $start = microtime(true);
        $plan = app(TreeProposalService::class)->preview($corporateBanking);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'A cyclic manager edge must not hang the walk.');
        $this->assertNotEmpty($plan['unplaced'], 'The loop must be reported, not silently dropped.');

        foreach ($plan['unplaced'] as $entry) {
            $this->assertStringContainsString('loop', $entry['reason']);
        }
    }

    /* ================================================================== */
    /*  Criterion 8 — provider errors classify correctly, no message leaks. */
    /* ================================================================== */

    #[Test]
    public function criterion_8_a_401_on_the_token_request_classifies_without_leaking_a_message(): void
    {
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided for client with id ABC-SENSITIVE.',
            ], 401),
        ]);

        // `testConnection()` never lets a `DirectorySyncException` escape —
        // it is the one caller that reports failure as data, not an
        // exception, so the connector screen can show it.
        $result = app(EntraGraphClient::class)->testConnection($connector);
        $this->assertFalse($result['ok']);
        $this->assertSame('token_http_401', $result['error_class']);
        $this->assertStringNotContainsString('ABC-SENSITIVE', (string) $result['error_code']);
    }

    #[Test]
    public function criterion_8_a_429_with_retry_after_classifies_without_leaking_a_message(): void
    {
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Sensitive detail: tenant ABC is throttled']], 429, ['Retry-After' => '30']),
        ]);

        $this->expectException(DirectorySyncException::class);

        try {
            // `users()` is typed `iterable`, not `Generator` — iterating with
            // a `foreach` (rather than calling `->current()`, which only a
            // `Generator` declares) is what actually triggers the request,
            // and is the shape static analysis can follow too.
            foreach (app(EntraGraphClient::class)->users($connector) as $ignored) {
                break;
            }
        } catch (DirectorySyncException $e) {
            $this->assertSame('graph_rate_limited', $e->errorClass);
            $this->assertSame('30', $e->errorCode);
            $this->assertStringNotContainsString('Sensitive detail', $e->getMessage());

            throw $e;
        }
    }

    #[Test]
    public function criterion_8_a_manager_404_means_no_manager_not_a_failure(): void
    {
        $connector = $this->connector();

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/v1.0/users/EXEC-1/manager*' => Http::response([], 404),
        ]);

        $managerId = app(EntraGraphClient::class)->managerObjectId($connector, 'EXEC-1');

        $this->assertNull($managerId, 'A 404 from the manager endpoint means "no manager", not a failure.');
    }

    #[Test]
    public function criterion_8_a_mid_run_failure_produces_a_partial_status_and_a_bounded_error(): void
    {
        $connector = $this->connector();
        [$users] = (new IdentityDemoSeeder)->buildDirectory(1);

        $fake = $this->swapDirectoryClient((new FakeDirectoryClient($users))->failAfter(50, 'graph_http_503', '503'));

        $run = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame('graph_http_503', $run->error_class);
        $this->assertSame('503', $run->error_code);
        $this->assertGreaterThan(0, $run->directory_objects_read);
        $this->assertLessThan(200, $run->directory_objects_read);

        // A partial read must never sweep for leavers against an incomplete
        // directory — nobody in the unread tail may be flagged as gone.
        $this->assertSame(0, $run->leaver_count);
    }

    /* ================================================================== */
    /*  Criterion 9 — permissions, tenancy, audit, schema. */
    /* ================================================================== */

    #[Test]
    public function criterion_9_every_state_change_writes_an_audit_row(): void
    {
        $connector = $this->connector();
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([
            new DirectoryUser('A-1', 'a1@khb.test', 'Person A', 'a1@khb.test', '+2348000000021', null, 'Officer', 'BU-OP', null, 'EA1', true, null),
        ]));

        $run = $this->service()->runFull($connector, SyncTrigger::Manual, $this->admin->id);

        $this->assertTrue(
            AuditLog::query()->where('auditable_type', \App\Models\Bcms\IdentitySyncRun::class)
                ->where('auditable_id', $run->getKey())->where('event', 'identity.sync.started')->exists(),
        );
        $this->assertTrue(
            AuditLog::query()->where('auditable_type', \App\Models\Bcms\IdentitySyncRun::class)
                ->where('auditable_id', $run->getKey())->where('event', 'identity.sync.finished')->exists(),
        );

        $contact = Contact::query()->where('ad_object_guid', 'A-1')->firstOrFail();
        $this->assertTrue(
            AuditLog::query()->where('auditable_type', Contact::class)
                ->where('auditable_id', $contact->getKey())->where('event', 'contact.synced')->exists(),
        );
    }

    #[Test]
    public function criterion_9_the_bcms_schema_manifest_is_clean(): void
    {
        $this->artisan('bcms:verify-schema')->assertExitCode(0);
    }

    /* ================================================================== */
    /*  Regression — MariaDB's implicit TIMESTAMP default/on-update clause */
    /* ================================================================== */

    /**
     * `bcms_identity_sync_runs.started_at` was a NOT-NULL `timestamp` column
     * with no explicit default. On a server running with
     * `explicit_defaults_for_timestamp` OFF (MariaDB's legacy compatibility
     * mode — confirmed on this box), such a column silently gets
     * `DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()` attached by
     * the server itself. `finish()`/`abort()` both UPDATE the row well after
     * `started_at` was written, which was silently rewriting it to the
     * moment of THAT update, in the server's SYSTEM time zone rather than
     * PHP's UTC `now()` — every completed run showed `finished_at` about an
     * hour BEFORE `started_at`. The columns are now `dateTime`, which never
     * carries an implicit default or auto-update in MySQL/MariaDB.
     *
     * MUTATION: revert either column in the migration back to `timestamp()`
     * and this fails on this server (it would pass on a server running with
     * `explicit_defaults_for_timestamp` ON, which is exactly how the defect
     * shipped unnoticed).
     */
    #[Test]
    public function a_completed_run_never_finishes_before_it_started(): void
    {
        $connector = $this->connector();
        $this->swapDirectoryClient(new FakeDirectoryClient([
            new DirectoryUser('TZ-1', 'tz1@khb.test', 'Person TZ', 'tz1@khb.test', '+2348000000099', null, 'Officer', 'BU-OP', null, 'ETZ1', true, null),
        ]));

        $run = $this->service()->runFull($connector, SyncTrigger::Manual);
        $run->refresh();

        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertTrue(
            $run->finished_at->gte($run->started_at),
            sprintf(
                'finished_at (%s) must never be before started_at (%s).',
                $run->finished_at->toIso8601String(),
                $run->started_at->toIso8601String(),
            ),
        );

        // The raw column, bypassing Eloquent's cast entirely — the bug was a
        // MariaDB-server behaviour, not a PHP-level one, and a regression
        // that only showed up after a cast reinterpreted the value would
        // pass this test for the wrong reason.
        $raw = \Illuminate\Support\Facades\DB::table('bcms_identity_sync_runs')->where('id', $run->getKey())->first();
        $this->assertLessThanOrEqual(
            strtotime((string) $raw->finished_at),
            strtotime((string) $raw->started_at),
            'The raw stored started_at must not be later than the raw stored finished_at.',
        );
    }

    /* ================================================================== */
    /*  Regression — the seeder resolved the service before the fake */
    /* ================================================================== */

    /**
     * `IdentityDemoSeeder::run()` used to resolve `DirectorySyncService`
     * from the container BEFORE binding `FakeDirectoryClient` — the service
     * had already been constructed with whatever `DirectoryClient` the
     * container held at that moment (the real `EntraGraphClient`), so
     * swapping the binding afterwards changed nothing on the already-built
     * instance. Run as shipped, the demo seeder placed a real HTTP call to
     * Entra with its fake demo credentials the moment it ran.
     * `Http::preventStrayRequests()` is the enforcement: if the ordering
     * regresses, this fails with a `StrayRequestException` rather than a
     * seeder that quietly worked on a laptop with fake credentials that
     * happen to resolve nowhere.
     */
    #[Test]
    public function the_demo_seeder_never_reaches_the_network(): void
    {
        Http::preventStrayRequests();

        (new IdentityDemoSeeder)->run($this->organization);

        $this->assertTrue(IdentityConnector::query()->where('organization_id', $this->organization->id)->exists());
        $this->assertSame(200, Contact::query()->where('organization_id', $this->organization->id)->count());
        $this->assertGreaterThanOrEqual(1, IdentitySyncRun::query()->where('organization_id', $this->organization->id)->count());
    }

    /* ================================================================== */
    /*  NDPA register §7.5.1 — before_json/after_json/impact_json must never */
    /*  reach bcms_audit_logs, an append-only table with no purge path. */
    /*  ADR 0018 §2.2 point 6: the run/change rows are already the audit */
    /*  trail of a directory read; an audit row over IdentitySyncChange */
    /*  should say a change was staged/decided/applied, not carry a second */
    /*  copy of the synced person's name, mobile, email and department. */
    /* ================================================================== */

    #[Test]
    public function no_synced_personal_data_reaches_the_audit_log_through_a_sync_change(): void
    {
        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $this->swapDirectoryClient(new FakeDirectoryClient([
            new DirectoryUser('AUD-1', 'aud1@khb.test', 'Audit Person', 'aud1@khb.test', '+2348000000077', null, 'Officer', 'BU-OP', null, 'EAUD1', true, null),
        ]));

        $run = $this->service()->runFull($connector, SyncTrigger::Manual, $this->admin->id);

        $change = IdentitySyncChange::query()->where('sync_run_id', $run->getKey())->firstOrFail();

        // Decide it too, so an `updated` row (decision/decided_by/applied_at)
        // is covered by the same assertion, not only the `created` row.
        if ($change->decision === SyncChangeDecision::Pending) {
            $this->service()->decide($change, SyncChangeDecision::Approved, $this->admin->id);
        }

        $rows = AuditLog::query()
            ->where('auditable_type', IdentitySyncChange::class)
            ->where('auditable_id', $change->getKey())
            ->get();

        $this->assertNotEmpty($rows, 'The change must still carry an audit trail — only the JSON payload is excluded, not the row.');

        foreach ($rows as $row) {
            $haystack = json_encode([$row->before, $row->after]);

            $this->assertStringNotContainsString(
                '+2348000000077', (string) $haystack,
                'A synced mobile number (before_json/after_json) must never reach bcms_audit_logs.',
            );
            $this->assertStringNotContainsString(
                'aud1@khb.test', (string) $haystack,
                'A synced email address (before_json/after_json) must never reach bcms_audit_logs.',
            );
        }
    }

    /* ================================================================== */
    /*  QA gate — a requires_ack row must not be applicable through the */
    /*  BULK-decide HTTP endpoint. ADR 0018 §3.2/§5: "there is no `all`... */
    /*  a change that breaks a call tree or empties a saved audience */
    /*  requires BC-admin acknowledgement", and the review screen */
    /*  disables the row's checkbox unconditionally so it can never enter */
    /*  the selection the JSX bulk-decide button posts. That is a */
    /*  client-side control only: `BulkDecideIdentitySyncChangesRequest` */
    /*  validates an id against `organization_id`/`sync_run_id`/ */
    /*  `decision = pending` and nothing else, so a `change_ids` array */
    /*  built outside the JSX (curl, an API client, a compromised or */
    /*  simply differently-written front end) can carry a requires_ack id */
    /*  straight through `DirectorySyncService::decide()` into */
    /*  `ChangeApplier`, deactivating a Tier-2 call-tree node's contact */
    /*  without the acknowledgement ADR 0018 calls unconditional. */
    /* ================================================================== */

    #[Test]
    public function a_requires_ack_row_cannot_be_applied_through_bulk_decide(): void
    {
        // `safe_only` for run 1 — a joiner never requires acknowledgement
        // (`ImpactAssessor` only assesses leavers/movers), so this is what
        // actually populates `bcms_contacts` and lets a tree be built.
        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $seeder = new IdentityDemoSeeder;
        [$usersV1, $objectIds, $loopIds] = $seeder->buildDirectory(1);

        $fake = $this->swapDirectoryClient(new FakeDirectoryClient($usersV1));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $operations = BusinessUnit::query()
            ->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->first();

        $tree = app(TreeProposalService::class)->generate($operations, CallTreeType::Department, 'Operations (bulk-decide guard test)');
        app(CallTreeService::class)->approve($tree);

        $victimNode = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 2)->whereNotNull('contact_id')->first();
        $this->assertNotNull($victimNode, 'The fixture must produce at least one Tier-2 node to be the victim.');
        $victimObjectId = $victimNode->contact->ad_object_guid;

        // One ordinary leaver from OUTSIDE Operations, so it carries no tree
        // impact of its own and stays a plain `requires_ack = false` row.
        $plainLeaverObjectId = collect($objectIds)
            ->reject(fn (string $id) => in_array($id, $loopIds, true) || $id === $victimObjectId || str_starts_with($id, 'BU-OP-'))
            ->first();
        $this->assertNotNull($plainLeaverObjectId);

        // `none` for run 2 — so the leaver detected with NO tree impact ALSO
        // stays pending rather than auto-applying, giving this test a normal
        // pending row to submit through bulk-decide alongside the
        // requires_ack row.
        $connector->forceFill(['auto_apply_policy' => 'none'])->save();

        [$usersV2] = $seeder->buildDirectory(2, removeObjectIds: [$victimObjectId, $plainLeaverObjectId], moveCount: 0);
        $fake->setUsers($usersV2);

        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $victimChange = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())->where('contact_id', $victimNode->contact_id)->where('kind', 'leaver')->firstOrFail();
        $plainChange = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')->where('id', '!=', $victimChange->getKey())->firstOrFail();

        $this->assertTrue($victimChange->requires_ack);
        $this->assertFalse($plainChange->requires_ack);
        $this->assertSame(SyncChangeDecision::Pending, $victimChange->decision);
        $this->assertSame(SyncChangeDecision::Pending, $plainChange->decision);

        $reviewer = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Reviewer',
            'email' => 'reviewer@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('bulk-guard-reviewer', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bcms.identity.review', 'web'));
        $reviewer->assignRole($role);

        // Both ids submitted together, exactly as a raw API client (not the
        // JSX, which never lets a requires_ack row's checkbox be checked)
        // could submit them.
        $this->actingAs($reviewer)->post(route('bcms.identity.changes.bulk-decide', $run2), [
            'decision' => 'approved',
            'change_ids' => [$victimChange->getKey(), $plainChange->getKey()],
        ]);

        $victimChange->refresh();
        $plainChange->refresh();
        $victimNode->contact->refresh();

        $this->assertTrue(
            (bool) $victimNode->contact->is_active,
            'A requires_ack change must never be applied by the bulk-decide endpoint — only an explicit, per-row acknowledgement (the single decide() route) may apply it. '
            .'BulkDecideIdentitySyncChangesRequest only validates organization_id/sync_run_id/decision=pending and does not exclude requires_ack rows, '
            .'so this currently applies (defect: app/Http/Requests/Bcms/BulkDecideIdentitySyncChangesRequest.php, '
            .'app/Http/Controllers/Bcms/IdentitySyncController.php::bulkDecide()).',
        );
        $this->assertSame(
            SyncChangeDecision::Pending,
            $victimChange->decision,
            'A requires_ack row must stay pending after a bulk-decide call that did not single it out for acknowledgement.',
        );

        // The plain leaver, which never required acknowledgement, is exactly
        // what bulk-decide is for and must still apply.
        $this->assertFalse((bool) Contact::query()->find($plainChange->contact_id)->is_active);
        $this->assertSame(SyncChangeDecision::Approved, $plainChange->decision);
    }

    /**
     * Builds the same requires_ack + plain-leaver fixture
     * `a_requires_ack_row_cannot_be_applied_through_bulk_decide` uses, for the
     * two gate-1 re-test cases that need it again: an all-requires_ack batch,
     * and the per-row decide() route applying a requires_ack change.
     *
     * @return array{0: IdentitySyncChange, 1: IdentitySyncChange, 2: IdentitySyncRun, 3: CallTreeNode, 4: User}
     */
    private function buildRequiresAckAndPlainChangeFixture(): array
    {
        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $seeder = new IdentityDemoSeeder;
        [$usersV1, $objectIds, $loopIds] = $seeder->buildDirectory(1);

        $fake = $this->swapDirectoryClient(new FakeDirectoryClient($usersV1));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $operations = BusinessUnit::query()
            ->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->first();

        $tree = app(TreeProposalService::class)->generate($operations, CallTreeType::Department, 'Operations (bulk-decide fixture)');
        app(CallTreeService::class)->approve($tree);

        $victimNode = CallTreeNode::query()->where('call_tree_id', $tree->getKey())->where('tier', 2)->whereNotNull('contact_id')->first();
        $this->assertNotNull($victimNode, 'The fixture must produce at least one Tier-2 node to be the victim.');
        $victimObjectId = $victimNode->contact->ad_object_guid;

        $plainLeaverObjectId = collect($objectIds)
            ->reject(fn (string $id) => in_array($id, $loopIds, true) || $id === $victimObjectId || str_starts_with($id, 'BU-OP-'))
            ->first();
        $this->assertNotNull($plainLeaverObjectId);

        $connector->forceFill(['auto_apply_policy' => 'none'])->save();

        [$usersV2] = $seeder->buildDirectory(2, removeObjectIds: [$victimObjectId, $plainLeaverObjectId], moveCount: 0);
        $fake->setUsers($usersV2);

        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $victimChange = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())->where('contact_id', $victimNode->contact_id)->where('kind', 'leaver')->firstOrFail();
        $plainChange = IdentitySyncChange::query()
            ->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')->where('id', '!=', $victimChange->getKey())->firstOrFail();

        $this->assertTrue($victimChange->requires_ack);
        $this->assertFalse($plainChange->requires_ack);

        $reviewer = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Fixture Reviewer',
            'email' => 'fixture-reviewer-'.uniqid().'@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('bulk-guard-reviewer', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bcms.identity.review', 'web'));
        $reviewer->assignRole($role);

        return [$victimChange, $plainChange, $run2, $victimNode, $reviewer];
    }

    /**
     * QA gate 1 re-test: a batch made up ENTIRELY of requires_ack ids must
     * apply nothing at all — not even fail to apply the requires_ack row
     * while silently leaving the caller with no explanation. It must write
     * the `identity.sync.bulk_decide_refused` audit row naming the offending
     * ids, and the redirect must carry an `error` flash rather than a bare
     * `success` (there is nothing to be silently quiet about: every id in
     * the batch was refused).
     */
    #[Test]
    public function a_batch_of_only_requires_ack_ids_applies_nothing_audits_and_flashes_an_error(): void
    {
        [$victimChange, , $run2, $victimNode, $reviewer] = $this->buildRequiresAckAndPlainChangeFixture();

        $response = $this->actingAs($reviewer)->from(route('bcms.identity.runs.show', $run2))
            ->post(route('bcms.identity.changes.bulk-decide', $run2), [
                'decision' => 'approved',
                'change_ids' => [$victimChange->getKey()],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString((string) $victimChange->getKey(), (string) session('error'));

        $victimChange->refresh();
        $victimNode->contact->refresh();

        $this->assertSame(SyncChangeDecision::Pending, $victimChange->decision, 'An all-requires_ack batch must decide nothing.');
        $this->assertTrue((bool) $victimNode->contact->is_active, 'An all-requires_ack batch must apply nothing.');

        $refusalRow = AuditLog::query()
            ->where('auditable_type', IdentitySyncRun::class)
            ->where('auditable_id', $run2->getKey())
            ->where('event', 'identity.sync.bulk_decide_refused')
            ->latest('id')
            ->first();

        $this->assertNotNull($refusalRow, 'The refused all-requires_ack attempt must still be audited on the run.');
        $this->assertContains(
            $victimChange->getKey(),
            $refusalRow->after['requires_ack_change_ids'] ?? [],
            'The refusal audit row must name the offending change id.',
        );
        $this->assertSame('approved', $refusalRow->after['decision_attempted'] ?? null);
    }

    /**
     * QA gate 1 re-test: the single `decide()` route — per-row, distinct
     * from bulk-decide — remains the one path that MAY apply a requires_ack
     * change, for a reviewer holding `bcms.identity.review`. This is the
     * "acknowledgement" ADR 0018 §3.2/§5 calls for: an explicit, individual
     * decision on the one row that carries the tree/audience impact, as
     * opposed to a batch action that never singled it out.
     */
    #[Test]
    public function a_requires_ack_change_can_still_be_approved_individually_by_a_reviewer(): void
    {
        [$victimChange, , $run2, $victimNode, $reviewer] = $this->buildRequiresAckAndPlainChangeFixture();

        $response = $this->actingAs($reviewer)->from(route('bcms.identity.runs.show', $run2))
            ->post(route('bcms.identity.changes.decide', [$run2, $victimChange]), [
                'decision' => 'approved',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $victimChange->refresh();
        $victimNode->contact->refresh();

        $this->assertSame(SyncChangeDecision::Approved, $victimChange->decision, 'The per-row decide() route must be able to approve a requires_ack change.');
        $this->assertSame($reviewer->getKey(), $victimChange->decided_by);
        $this->assertFalse((bool) $victimNode->contact->is_active, 'Once individually acknowledged, the leaver must apply.');
    }

    /**
     * Gate 2 advisory 8: `DirectorySyncService::decide()` must not
     * read-then-write. Two model instances standing in for two racing
     * requests over the same pending row — a double click, two open tabs —
     * must apply at most once.
     */
    #[Test]
    public function two_sequential_decides_on_one_change_apply_once(): void
    {
        $head = new DirectoryUser('HEAD-RACE-1', 'headrace1@khb.test', 'Race Head', 'headrace1@khb.test', '+2348000000013', null, 'Head', 'BU-OP', null, 'EHR1', true, null);

        // `none`, so nothing auto-applies — the joiner itself is approved
        // explicitly below, precisely so the leaver row this test races over
        // stays `pending` rather than being auto-applied the instant run 2
        // stages it.
        $connector = $this->connector(['auto_apply_policy' => 'none']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$head]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $joinerChange = IdentitySyncChange::query()->where('directory_object_id', 'HEAD-RACE-1')->where('kind', 'joiner')->firstOrFail();
        $this->service()->decide($joinerChange, SyncChangeDecision::Approved, $this->admin->id);

        $contact = Contact::query()->where('ad_object_guid', 'HEAD-RACE-1')->firstOrFail();

        $fake->setUsers([]);
        $run2 = $this->service()->runFull($connector, SyncTrigger::Manual);

        $changeId = IdentitySyncChange::query()->where('sync_run_id', $run2->getKey())->where('kind', 'leaver')->firstOrFail()->getKey();

        // Two independently-loaded instances of the SAME row, exactly what
        // two concurrent HTTP requests would each hold.
        $requestA = IdentitySyncChange::query()->findOrFail($changeId);
        $requestB = IdentitySyncChange::query()->findOrFail($changeId);

        $this->service()->decide($requestA, SyncChangeDecision::Approved, $this->admin->id);
        $this->service()->decide($requestB, SyncChangeDecision::Approved, $this->admin->id);

        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active, 'The leaver must still apply.');

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $contact->getKey())
                ->where('event', 'contact.deactivated')
                ->count(),
            'A second decide() racing an already-decided row must not re-apply it.',
        );

        $requestA->refresh();
        $this->assertSame(SyncChangeDecision::Approved, $requestA->decision);
        $this->assertNotNull($requestA->decided_at);
    }

    /**
     * Gate 2 advisory 4: a saved map naming a key that is not one of
     * `ChangeDetector::mapDirectoryUser()`'s recognised source properties
     * must be rejected — accepting it would let an admin save a mapping
     * entry that is silently never looked up (`userPrincipalName` and
     * `businessPhones` are real Graph/`DirectoryUser` names, but neither is
     * consulted through the map).
     */
    #[Test]
    public function directory_attribute_map_rejects_a_key_outside_the_recognised_source_properties(): void
    {
        $this->assertTrue(DirectoryAttributeMap::isValid(DirectoryAttributeMap::defaults()));

        $this->assertFalse(
            DirectoryAttributeMap::isValid(['userPrincipalName' => 'email']),
            'A key the detector never looks up must be rejected even though it names a real Graph attribute.',
        );

        $this->assertFalse(
            DirectoryAttributeMap::isValid(['businessPhones' => 'mobile_primary']),
            'A `$select` field that is not a recognised source property must be rejected.',
        );

        $this->assertFalse(
            DirectoryAttributeMap::isValid(['notARealProperty' => 'full_name']),
            'An unrecognised key must be rejected regardless of the value.',
        );

        $this->assertFalse(
            DirectoryAttributeMap::isValid(['displayName' => 'whatsapp']),
            'A value outside the write allowlist must still be rejected.',
        );
    }

    /* ================================================================== */
    /*  QA gate — the delta path, un-covered by any of the nine numbered */
    /*  criteria (phase-2c-notes.md §2 deviation 4: "the delta path has no */
    /*  acceptance test... qa-engineer should add a delta-specific test */
    /*  before go-live"). `ChangeDetector::detectDelta()` and */
    /*  `DirectorySyncService::runDelta()` were otherwise exercised only by */
    /*  `Phase2cRuntimeTest`'s SSRF drift test, which asserts */
    /*  `Http::assertNothingSent()` — the guard refuses the call before any */
    /*  detection logic ever runs. This is the first test that drives a */
    /*  real delta cycle through `FakeDirectoryClient::withDeltaChanges()` */
    /*  end to end and inspects what it actually stages (ADR 0018 §3.1: a */
    /*  delta carries attribute changes and accountEnabled flips, never a */
    /*  relationship, so it must never itself produce a `mover` even when */
    /*  the raw directory attribute changes department). */
    /* ================================================================== */

    #[Test]
    public function a_delta_run_stages_a_joiner_a_leaver_and_a_contact_change_but_never_a_mover(): void
    {
        $operations = BusinessUnit::query()->where('organization_id', $this->organization->id)->where('code', 'BU-OP')->firstOrFail();
        $it = BusinessUnit::query()->where('organization_id', $this->organization->id)->where('code', 'BU-IT')->firstOrFail();

        $leaverToBe = new DirectoryUser('DELTA-LEAVER-1', 'dleaver1@khb.test', 'Delta Leaver', 'dleaver1@khb.test', '+2348000000050', null, 'Officer', 'BU-OP', null, 'EDL1', true, null);
        $drifter = new DirectoryUser('DELTA-DRIFT-1', 'ddrift1@khb.test', 'Delta Drifter', 'ddrift1@khb.test', '+2348000000051', null, 'Officer', 'BU-OP', null, 'EDD1', true, null);

        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta', 'auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$leaverToBe, $drifter]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $leaverContact = Contact::query()->where('ad_object_guid', 'DELTA-LEAVER-1')->firstOrFail();
        $drifterContact = Contact::query()->where('ad_object_guid', 'DELTA-DRIFT-1')->firstOrFail();
        $this->assertSame($operations->id, $drifterContact->business_unit_id);

        // The delta read: the leaver's account is now disabled; the drifter's
        // title AND department both changed (department must be IGNORED —
        // §3.1 says delta never carries a relationship, and business_unit_id
        // is not one either, being derived from the directory's department
        // string exactly the way a manager edge is); and a brand new object
        // joins, never seen by any previous read.
        $leaverDisabled = new DirectoryUser('DELTA-LEAVER-1', 'dleaver1@khb.test', 'Delta Leaver', 'dleaver1@khb.test', '+2348000000050', null, 'Officer', 'BU-OP', null, 'EDL1', false, null);
        $drifterChanged = new DirectoryUser('DELTA-DRIFT-1', 'ddrift1@khb.test', 'Delta Drifter', 'ddrift1@khb.test', '+2348000000051', null, 'Senior Officer', 'BU-IT', null, 'EDD1', true, null);
        $newJoiner = new DirectoryUser('DELTA-JOIN-1', 'djoin1@khb.test', 'Delta Joiner', 'djoin1@khb.test', '+2348000000052', null, 'Officer', 'BU-OP', null, 'EDJ1', true, null);

        $fake->setUsers([$leaverDisabled, $drifterChanged, $newJoiner])
            ->withDeltaChanges(['DELTA-LEAVER-1', 'DELTA-DRIFT-1', 'DELTA-JOIN-1'], 'delta-cursor-2');

        $run = $this->service()->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertSame(SyncRunStatus::Success, $run->status);
        $this->assertSame(1, $run->joiner_count, 'The new object must stage a joiner.');
        $this->assertSame(1, $run->leaver_count, 'The disabled account must stage a leaver.');
        $this->assertSame(1, $run->contact_change_count, 'The drifted title must stage a contact_change.');
        $this->assertSame(0, $run->mover_count, 'A delta must never itself produce a mover — §3.1: it carries no relationship.');

        $leaverContact->refresh();
        $this->assertFalse((bool) $leaverContact->is_active, 'safe_only auto-applies a leaver with no call-tree impact.');

        $drifterContact->refresh();
        $this->assertSame('Senior Officer', $drifterContact->title, 'The drifted title is a legitimate attribute change and must still apply.');
        $this->assertSame(
            $operations->id,
            $drifterContact->business_unit_id,
            'The department drift must NOT re-parent the contact — a delta never resolves org placement, only a full reconciliation does.',
        );
        $this->assertNotSame($it->id, $drifterContact->business_unit_id);

        $this->assertTrue(Contact::query()->where('ad_object_guid', 'DELTA-JOIN-1')->exists(), 'The joiner must have been applied.');

        $connector->refresh();
        $this->assertSame('delta-cursor-2', $connector->delta_link, 'The new delta cursor must be persisted for the next run.');
    }

    /* ================================================================== */
    /*  Gate 2 rejection #3, blocking defect 1 — a partial delta payload */
    /*  is not a complete user, and an @removed tombstone is a leaver */
    /*  signal, not an ordinary user (ADR 0018 §3.1). */
    /* ================================================================== */

    #[Test]
    public function a_partial_delta_entry_for_an_already_inactive_contact_does_not_reactivate_it(): void
    {
        $seed = new DirectoryUser('DELTA-INACTIVE-1', 'dinact1@khb.test', 'Inactive One', 'dinact1@khb.test', '+2348000000060', null, 'Officer', 'BU-OP', null, 'EDI1', true, null);

        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta', 'auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([$seed]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'DELTA-INACTIVE-1')->firstOrFail();

        // Deactivated by the ordinary absence path: a second full read the
        // object is simply not present in.
        $fake->setUsers([]);
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact->refresh();
        $this->assertFalse((bool) $contact->is_active, 'setup: the contact must already be inactive before the delta under test.');

        // The delta under test: Graph reports ONLY a changed jobTitle for
        // this object — a real partial entry never repeats a property that
        // did not change, so `accountEnabled` is not in the payload at all.
        // Today's code defaults a missing `accountEnabled` to `true` and
        // reactivates the contact on the strength of an unrelated title
        // change.
        $fake->withPartialDeltaEntry('DELTA-INACTIVE-1', ['jobTitle' => 'Senior Officer']);

        $run = $this->service()->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertSame(SyncRunStatus::Success, $run->status);

        $contact->refresh();
        $this->assertFalse(
            (bool) $contact->is_active,
            'A partial delta entry (no accountEnabled reported) must never reactivate an already-inactive contact.',
        );
        $this->assertSame(
            0,
            $run->contact_change_count + $run->mover_count,
            'Nothing is staged from an unreported enable/disable signal on an already-departed contact.',
        );
    }

    #[Test]
    public function a_partial_delta_entry_for_an_unmatched_directory_object_stages_no_joiner(): void
    {
        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta', 'auto_apply_policy' => 'safe_only']);
        $fake = $this->swapDirectoryClient(new FakeDirectoryClient([]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        // A delta entry for an object this connector has never matched to a
        // contact, reporting only a changed jobTitle — no `accountEnabled`,
        // so this is not evidence the object is new, only that it is
        // unfamiliar. Today's code stages it as a joiner anyway, and
        // `bcms_contacts.full_name` being NOT NULL (nothing here supplied a
        // `displayName`) throws on every apply — the run fails every time
        // the schedule ticks.
        $fake->withPartialDeltaEntry('DELTA-UNKNOWN-1', ['jobTitle' => 'Officer']);

        $run = $this->service()->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertContains(
            $run->status,
            [SyncRunStatus::Success, SyncRunStatus::Partial],
            'A partial delta entry for an unmatched object must never fail the whole run.',
        );
        $this->assertSame(
            0,
            $run->joiner_count,
            'A joiner needs a full object; a partial entry for an unknown directory object must not stage one.',
        );
        $this->assertFalse(Contact::query()->where('ad_object_guid', 'DELTA-UNKNOWN-1')->exists());
    }

    /**
     * Routed through the REAL `EntraGraphClient` (`Http::fake()`, not
     * `FakeDirectoryClient`), deliberately. The defect this guards lives in
     * `EntraGraphClient::delta()`'s own translation of a raw `@removed`
     * entry — `FakeDirectoryClient`, which every other test in this section
     * uses, only ever hands `ChangeDetector` a `DirectoryUser` a TEST
     * constructed directly, so a fixture built with `accountEnabled: false`
     * would pass against this exact scenario whether or not
     * `EntraGraphClient` had ever been fixed. Only the raw-JSON path proves
     * the whole thing end to end: the tombstone reaches Graph's wire shape,
     * `EntraGraphClient` collapses it, `ChangeDetector` stages it, and
     * `DirectorySyncService`/`ChangeApplier`/`ImpactAssessor` apply it —
     * exactly as any other leaver would be.
     */
    #[Test]
    public function a_removed_tombstone_stages_a_leaver_with_the_usual_impact_treatment(): void
    {
        $victim = new DirectoryUser('DELTA-REMOVED-1', 'drem1@khb.test', 'Removed One', 'drem1@khb.test', '+2348000000061', null, 'Officer', 'BU-OP', null, 'EDR1', true, null);

        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta', 'auto_apply_policy' => 'safe_only']);
        $this->swapDirectoryClient(new FakeDirectoryClient([$victim]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'DELTA-REMOVED-1')->firstOrFail();
        $this->assertTrue((bool) $contact->is_active);

        // The real client now, against Graph's own wire shape for a deleted
        // or out-of-scope object: no properties beyond `id` and `@removed`.
        // Today's code hands that straight to `DirectoryUser::
        // fromGraphAttributes()`, whose missing-`accountEnabled` default of
        // `true` reads it as an ordinary, unchanged, still-enabled user —
        // the leaver is silently never staged and the contact stays live on
        // the emergency roster.
        app()->bind(DirectoryClient::class, EntraGraphClient::class);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/v1.0/users/delta*' => Http::response([
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/delta?$deltatoken=removed-1',
                'value' => [
                    ['id' => 'DELTA-REMOVED-1', '@removed' => ['reason' => 'deleted']],
                ],
            ], 200),
        ]);

        $run = $this->service()->runDelta($connector, SyncTrigger::ScheduledDelta);

        $this->assertSame(SyncRunStatus::Success, $run->status);
        $this->assertSame(1, $run->leaver_count, 'An @removed tombstone must stage exactly one leaver.');

        $contact->refresh();
        $this->assertFalse(
            (bool) $contact->is_active,
            'safe_only auto-applies a leaver with no call-tree impact — the usual treatment, unaffected by the tombstone shape.',
        );

        $change = IdentitySyncChange::query()
            ->where('sync_run_id', $run->getKey())
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertSame(SyncChangeKind::Leaver, $change->kind);
        $this->assertFalse(
            (bool) $change->requires_ack,
            'No call-tree/saved-group impact for this contact — the ordinary ImpactAssessor path, reached exactly as any other leaver.',
        );

        $connector->refresh();
        $this->assertSame('https://graph.microsoft.com/v1.0/users/delta?$deltatoken=removed-1', $connector->delta_link);
    }

    /**
     * The translation itself, at `EntraGraphClient::delta()` — proven
     * directly against a raw Graph payload rather than only through the
     * staged outcome above, because the defect lived in the client's own
     * `fromGraphAttributes()` call, not only in what `ChangeDetector` did
     * with the result.
     */
    #[Test]
    public function entra_graph_client_delta_reports_unreported_enablement_as_null_and_a_removed_tombstone_as_disabled(): void
    {
        $connector = $this->connector(['sync_schedule' => 'nightly_plus_delta']);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/v1.0/users/delta*' => Http::response([
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/delta?$deltatoken=raw-1',
                'value' => [
                    ['id' => 'RAW-PARTIAL-1', 'jobTitle' => 'Officer'],
                    ['id' => 'RAW-REMOVED-1', '@removed' => ['reason' => 'deleted']],
                    ['id' => 'RAW-FULL-1', 'displayName' => 'Full One', 'accountEnabled' => true],
                ],
            ], 200),
        ]);

        $result = app(EntraGraphClient::class)->delta($connector);

        $byId = [];

        foreach ($result['users'] as $user) {
            $byId[$user->objectId] = $user;
        }

        $this->assertNull(
            $byId['RAW-PARTIAL-1']->accountEnabled,
            'A property-only delta entry must report accountEnabled as unreported (null), never default to true.',
        );
        $this->assertFalse(
            $byId['RAW-REMOVED-1']->accountEnabled,
            'An @removed tombstone (either Graph reason) must collapse to accountEnabled: false.',
        );
        $this->assertTrue($byId['RAW-FULL-1']->accountEnabled);
    }

    /* ================================================================== */
    /*  Gate 2 rejection #4, blocking defect 1 — Graph's `accountEnabled` */
    /*  is a NULLABLE Edm.Boolean: a guest/external or partially-readable */
    /*  object can report the key WITH a JSON `null` VALUE, not merely */
    /*  omit it. `DirectoryUser::fromGraphAttributes()` used to coerce a */
    /*  present-but-null value to `(bool) null === false` — an explicit, */
    /*  wrong "disabled" reading of what Graph itself reported as unknown. */
    /*  Both tests are routed through the REAL `EntraGraphClient` */
    /*  (`Http::fake()`), on the FULL-run path, so the raw-JSON translation */
    /*  itself is exercised — a `FakeDirectoryClient` fixture built with */
    /*  `accountEnabled: false` would pass whether or not the client's */
    /*  own null-handling had ever been fixed. */
    /* ================================================================== */

    #[Test]
    public function a_full_read_reporting_accountenabled_as_an_explicit_null_does_not_leaver_a_matched_active_contact(): void
    {
        $enabled = new DirectoryUser('RAW-NULL-MATCHED-1', 'nullmatched1@khb.test', 'Null Matched One', 'nullmatched1@khb.test', '+2348000000062', null, 'Officer', 'BU-OP', null, 'ENM1', true, null);

        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $this->swapDirectoryClient(new FakeDirectoryClient([$enabled]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        $contact = Contact::query()->where('ad_object_guid', 'RAW-NULL-MATCHED-1')->firstOrFail();
        $this->assertTrue((bool) $contact->is_active);

        // The real client now, against Graph's own wire shape for a guest/
        // external or partially-readable object: `accountEnabled` IS
        // PRESENT in the payload, but its value is JSON `null`, not merely
        // absent. `'manager' => null` is included explicitly too, so this
        // test exercises only the property under test — without it,
        // `EntraGraphClient::toDirectoryUser()`'s missing-`manager`-key
        // fallback would issue an unfaked `/manager` request and fail under
        // `Http::preventStrayRequests()`.
        app()->bind(DirectoryClient::class, EntraGraphClient::class);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/v1.0/users*' => Http::response([
                'value' => [
                    [
                        'id' => 'RAW-NULL-MATCHED-1',
                        'userPrincipalName' => 'nullmatched1@khb.test',
                        'displayName' => 'Null Matched One',
                        'mail' => 'nullmatched1@khb.test',
                        'mobilePhone' => '+2348000000062',
                        'jobTitle' => 'Officer',
                        'department' => 'BU-OP',
                        'employeeId' => 'ENM1',
                        'manager' => null,
                        'accountEnabled' => null,
                    ],
                ],
            ], 200),
        ]);

        $run = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(SyncRunStatus::Success, $run->status, 'The rest of the run must still succeed.');
        $this->assertSame(
            0,
            $run->leaver_count,
            'An unknown (explicit null) enablement state must never stage a leaver for a matched, active contact.',
        );

        $contact->refresh();
        $this->assertTrue(
            (bool) $contact->is_active,
            'A matched, active contact must stay active when Entra reports accountEnabled as an explicit null.',
        );
    }

    #[Test]
    public function a_full_read_reporting_accountenabled_as_an_explicit_null_for_an_unmatched_object_stages_no_joiner_and_is_logged(): void
    {
        $connector = $this->connector(['auto_apply_policy' => 'safe_only']);
        $this->swapDirectoryClient(new FakeDirectoryClient([]));
        $this->service()->runFull($connector, SyncTrigger::Manual);

        app()->bind(DirectoryClient::class, EntraGraphClient::class);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeJwt(['User.Read.All']), 'expires_in' => 3600], 200),
            'https://graph.microsoft.com/v1.0/users*' => Http::response([
                'value' => [
                    [
                        'id' => 'RAW-NULL-UNMATCHED-1',
                        'jobTitle' => 'Officer',
                        'manager' => null,
                        'accountEnabled' => null,
                    ],
                ],
            ], 200),
        ]);

        // `ChangeDetector::joiner()` now logs this exact line (advisory 2)
        // only when the unmatched object's own enablement is unreported
        // (`=== null`) — organization_id and directory_object_id only,
        // never a name, mail or UPN.
        $captured = null;
        Log::listen(function ($event) use (&$captured) {
            if ($event->message === 'BCMS identity sync: partial entry for an unmatched directory object was not staged') {
                $captured = $event->context;
            }
        });

        $run = $this->service()->runFull($connector, SyncTrigger::Manual);

        $this->assertSame(
            0,
            $run->joiner_count,
            'An unknown (explicit null) enablement state for an unmatched object must never stage a joiner.',
        );
        $this->assertFalse(Contact::query()->where('ad_object_guid', 'RAW-NULL-UNMATCHED-1')->exists());

        $this->assertNotNull($captured, 'The unmatched, unreported-enablement object must be logged.');
        $this->assertSame(
            ['organization_id' => $this->organization->id, 'directory_object_id' => 'RAW-NULL-UNMATCHED-1'],
            $captured,
        );
    }
}
