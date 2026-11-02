<?php

namespace Tests\Feature\Kri;

use App\Contracts\DirectoryProfiles;
use App\Enums\KriContactRole;
use App\Services\Kri\BcmsDirectoryProfiles;
use App\Support\DirectoryProfile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantFixture;

/**
 * ADR 0026 section 9 and ADR 0018: the directory hint is READ from what BCMS
 * identity sync already wrote. It never calls a directory, never writes, returns
 * [] when BCMS is off or the tenant has no active connector, and costs a
 * constant number of queries.
 */
class BcmsDirectoryProfilesTest extends KriCycleTestCase
{
    private TenantFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new TenantFixture;
        config(['features.bcms' => true]);
    }

    private function reader(): BcmsDirectoryProfiles
    {
        return app(BcmsDirectoryProfiles::class);
    }

    private function connector(int $organizationId, bool $active = true): void
    {
        $this->fixture->make('bcms_identity_connectors', $organizationId, ['is_active' => $active]);
    }

    /** @param array<string, mixed> $overrides */
    private function contact(int $organizationId, int $userId, array $overrides = []): int
    {
        return $this->fixture->make('bcms_contacts', $organizationId, array_merge([
            'user_id' => $userId,
            'ad_object_guid' => bin2hex(random_bytes(16)),
            'ad_synced_at' => '2026-11-01 02:30:00',
            'is_active' => true,
            'title' => 'Treasury Analyst',
            'business_unit_id' => $this->unit->id,
        ], $overrides));
    }

    #[Test]
    public function it_is_bound_to_the_contract_in_the_container(): void
    {
        $this->assertInstanceOf(BcmsDirectoryProfiles::class, app(DirectoryProfiles::class));
    }

    #[Test]
    public function it_returns_nothing_when_bcms_is_off_even_if_everything_is_synced(): void
    {
        $user = $this->makeStaff('Synced User');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id);
        config(['features.bcms' => false]);

        DB::enableQueryLog();
        $this->assertSame([], $this->reader()->forUsers($this->organization->id, [$user->id]));
        $this->assertSame(0, count(DB::getQueryLog()), 'BCMS off costs no query at all');
    }

    #[Test]
    public function it_returns_nothing_without_an_active_identity_connector(): void
    {
        $user = $this->makeStaff('Synced User');
        $this->contact($this->organization->id, $user->id);

        $this->assertSame([], $this->reader()->forUsers($this->organization->id, [$user->id]), 'no connector at all');

        $this->connector($this->organization->id, active: false);
        $this->assertSame([], $this->reader()->forUsers($this->organization->id, [$user->id]), 'only an inactive connector');
    }

    #[Test]
    public function it_returns_nothing_for_an_empty_list_without_touching_the_database(): void
    {
        DB::enableQueryLog();

        $this->assertSame([], $this->reader()->forUsers($this->organization->id, []));
        $this->assertSame([], $this->reader()->forUsers($this->organization->id, [0]));
        $this->assertSame(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_reports_the_synced_department_title_state_and_time(): void
    {
        $user = $this->makeStaff('Synced User');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id);

        $profiles = $this->reader()->forUsers($this->organization->id, [$user->id]);

        $this->assertArrayHasKey($user->id, $profiles);
        $profile = $profiles[$user->id];
        $this->assertInstanceOf(DirectoryProfile::class, $profile);
        $this->assertTrue($profile->synced);
        $this->assertSame('Treasury', $profile->department, 'the NAME of the business unit the sync resolved the person to');
        $this->assertSame('Treasury Analyst', $profile->jobTitle);
        $this->assertTrue($profile->accountEnabled);
        $this->assertSame('2026-11-01 02:30:00', $profile->lastSyncedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_disabled_directory_account_reads_as_not_enabled(): void
    {
        $user = $this->makeStaff('Leaver');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['is_active' => false]);

        $this->assertFalse($this->reader()->forUsers($this->organization->id, [$user->id])[$user->id]->accountEnabled);
    }

    #[Test]
    public function a_synced_contact_with_no_resolved_unit_has_no_department_text(): void
    {
        $user = $this->makeStaff('Unmapped');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['business_unit_id' => null]);

        $profile = $this->reader()->forUsers($this->organization->id, [$user->id])[$user->id];

        $this->assertTrue($profile->synced);
        $this->assertNull($profile->department);
    }

    #[Test]
    public function a_hand_made_or_deleted_contact_is_not_a_directory_profile(): void
    {
        $manual = $this->makeStaff('Manual Contact');
        $deleted = $this->makeStaff('Deleted Contact');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $manual->id, ['ad_object_guid' => null]);
        $this->contact($this->organization->id, $deleted->id, ['deleted_at' => '2026-10-01 00:00:00']);

        $this->assertSame([], $this->reader()->forUsers($this->organization->id, [$manual->id, $deleted->id]));
    }

    #[Test]
    public function the_most_recently_synced_contact_speaks_when_there_are_two(): void
    {
        $user = $this->makeStaff('Two Contacts');
        $ops = $this->makeUnit('Operations', 'OPS');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['ad_synced_at' => '2026-10-01 00:00:00', 'business_unit_id' => $this->unit->id]);
        $this->contact($this->organization->id, $user->id, ['ad_synced_at' => '2026-11-01 00:00:00', 'business_unit_id' => $ops->id]);

        $this->assertSame('Operations', $this->reader()->forUsers($this->organization->id, [$user->id])[$user->id]->department);
    }

    #[Test]
    public function it_is_tenant_isolated_in_both_directions(): void
    {
        $mine = $this->makeStaff('My User');
        $theirs = $this->foreignUser();
        $this->connector($this->organization->id);
        $this->connector($this->otherOrg->id);
        $this->contact($this->organization->id, $mine->id);
        // Their tenant has a directory row naming MY user id: it must not be read for me.
        $this->contact($this->otherOrg->id, $mine->id, ['business_unit_id' => null, 'title' => 'LEAKED']);
        $this->contact($this->otherOrg->id, $theirs->id, ['business_unit_id' => null]);

        $forMe = $this->reader()->forUsers($this->organization->id, [$mine->id, $theirs->id]);
        $this->assertSame([$mine->id], array_keys($forMe), "another tenant's user is not visible");
        $this->assertSame('Treasury Analyst', $forMe[$mine->id]->jobTitle);

        // And a tenant with no connector of its own gets nothing, whatever another has.
        $this->assertSame([], $this->reader()->forUsers($this->makeOrgWithoutConnector(), [$mine->id]));
    }

    private function makeOrgWithoutConnector(): int
    {
        return (int) \App\Models\Organization::create([
            'name' => 'Lonely Bank', 'short_name' => 'LONE', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ])->id;
    }

    #[Test]
    public function it_runs_a_constant_number_of_read_only_queries_whatever_the_number_of_users(): void
    {
        $this->connector($this->organization->id);
        $ids = [];

        for ($i = 0; $i < 30; $i++) {
            $user = $this->makeStaff("Bulk User {$i}");
            $ids[] = $user->id;
            $this->contact($this->organization->id, $user->id);
        }

        $count = function (array $subset) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->reader()->forUsers($this->organization->id, $subset);
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return $log;
        };

        $one = $count([$ids[0]]);
        $many = $count($ids);

        $this->assertSame(count($one), count($many), 'no per-user query');
        $this->assertLessThanOrEqual(4, count($many));
        foreach ($many as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])), 'the reader never writes');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The mismatch flag on the contacts screen */
    /* ------------------------------------------------------------------ */

    private ?\App\Models\User $contactsManager = null;

    private function contactsPage(): array
    {
        // Created once per test: some tests read the page twice.
        $manager = $this->contactsManager ??= $this->makeStaff('Contacts Manager', ['kri.manage_department_contacts']);

        return $this->actingAs($manager)->get(route('risk.kri.contacts.index'))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> the Treasury champion row */
    private function treasuryChampionRow(): array
    {
        $unit = collect($this->contactsPage()['departments'])->firstWhere('name', 'Treasury');

        return $unit['contacts']['risk_champion'][0];
    }

    #[Test]
    public function a_person_whose_directory_department_differs_from_the_unit_is_flagged_not_moved(): void
    {
        $ops = $this->makeUnit('Operations', 'OPS');
        $user = $this->makeStaff('Moved Person');
        $contact = $this->makeContact($this->unit, $user);
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['business_unit_id' => $ops->id]);

        $row = $this->treasuryChampionRow();

        $this->assertTrue($row['department_mismatch']);
        $this->assertSame('Operations', $row['directory']['department']);
        $this->assertTrue($row['directory']['synced']);
        $this->assertSame($this->unit->id, (int) $contact->fresh()->business_unit_id, 'a flag, never applied');
    }

    #[Test]
    public function a_person_whose_directory_department_matches_is_not_flagged(): void
    {
        $user = $this->makeStaff('Right Place');
        $this->makeContact($this->unit, $user);
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['business_unit_id' => $this->unit->id]);

        $row = $this->treasuryChampionRow();

        $this->assertFalse($row['department_mismatch']);
        $this->assertTrue($row['directory']['account_enabled']);
    }

    #[Test]
    public function unknown_is_not_a_mismatch_and_a_disabled_account_is_shown_but_changes_nothing(): void
    {
        $user = $this->makeStaff('Not Synced');
        $this->makeContact($this->unit, $user);

        $row = $this->treasuryChampionRow();
        $this->assertFalse($row['department_mismatch']);
        $this->assertNull($row['directory']);
        $this->assertTrue($row['is_active']);

        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['is_active' => false, 'business_unit_id' => $this->unit->id]);
        $row = $this->treasuryChampionRow();
        $this->assertFalse($row['directory']['account_enabled']);
        $this->assertFalse($row['department_mismatch']);
    }

    #[Test]
    public function with_bcms_off_the_contacts_screen_shows_no_directory_hint_at_all(): void
    {
        $user = $this->makeStaff('Moved Person');
        $this->makeContact($this->unit, $user);
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id, ['business_unit_id' => $this->makeUnit('Operations', 'OPS')->id]);
        config(['features.bcms' => false]);

        $row = $this->treasuryChampionRow();

        $this->assertNull($row['directory']);
        $this->assertFalse($row['department_mismatch']);
    }

    #[Test]
    public function the_user_search_carries_the_directory_hint_for_each_result(): void
    {
        $user = $this->makeStaff('Searchable Person');
        $this->connector($this->organization->id);
        $this->contact($this->organization->id, $user->id);
        $manager = $this->makeStaff('Contacts Manager', ['kri.manage_department_contacts']);

        $this->actingAs($manager)->getJson(route('risk.kri.contacts.users', ['q' => 'Searchable']))
            ->assertOk()
            ->assertJsonPath('0.name', 'Searchable Person')
            ->assertJsonPath('0.directory.synced', true)
            ->assertJsonPath('0.directory.department', 'Treasury')
            ->assertJsonPath('0.has_submit_permission', false);
    }

    #[Test]
    public function nothing_in_the_kri_directory_code_can_write_to_a_directory(): void
    {
        foreach (['app/Services/Kri/BcmsDirectoryProfiles.php', 'app/Services/Kri/DepartmentMismatchRule.php', 'app/Services/Kri/NullDirectoryProfiles.php'] as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertDoesNotMatchRegularExpression('/ldap_(add|modify|delete|rename|mod_)|->(insert|update|delete|create|save)\(|DB::(insert|update|delete)\(/i', $source, $file);
        }

        $this->assertNull(KriContactRole::tryFrom('directory_admin'));
    }
}
