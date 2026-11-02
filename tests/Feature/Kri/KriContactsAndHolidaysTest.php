<?php

namespace Tests\Feature\Kri;

use App\Enums\KriContactRole;
use App\Models\KriDepartmentContact;
use App\Models\OrganizationHoliday;
use App\Models\RiskAuditTrail;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0026 sections 2, 3, 13 and 15: the department contacts screen and the
 * organisation holiday list. Each write is asserted on the database and on the
 * audit trail, and each is refused to anyone without the permission.
 */
class KriContactsAndHolidaysTest extends KriCycleTestCase
{
    private User $manager;

    private User $holidayAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->makeStaff('Contacts Manager', ['kri.manage_department_contacts']);
        $this->holidayAdmin = $this->makeStaff('Holiday Admin', ['holiday.manage']);
    }

    private function addContact(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson(route('risk.kri.contacts.store'), $body);
    }

    /* ------------------------------------------------------------------ */
    /*  Contacts */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function adding_a_contact_saves_the_row_and_audits_it_on_the_business_unit(): void
    {
        $person = $this->makeStaff('Chidi Champion', ['kri.submit_return']);

        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $person->id, 'role' => 'risk_champion'])->assertRedirect();

        $contact = KriDepartmentContact::query()->firstOrFail();
        $this->assertSame($this->unit->id, (int) $contact->business_unit_id);
        $this->assertSame($person->id, (int) $contact->user_id);
        $this->assertSame(KriContactRole::RiskChampion, $contact->role);
        $this->assertSame($this->manager->id, (int) $contact->assigned_by);
        $this->assertSame($this->organization->id, (int) $contact->organization_id);

        $audit = RiskAuditTrail::query()->where('action_type', 'kri_contact_added')->firstOrFail();
        $this->assertSame('business_unit', $audit->entity_type, "audited on the department so its history is one query");
        $this->assertSame($this->unit->id, (int) $audit->entity_id);
        $this->assertSame('risk_champion', $audit->field_changed);
        $this->assertSame("user:{$person->id} Chidi Champion <{$person->email}>", $audit->new_value);
        $this->assertSame($this->manager->id, (int) $audit->changed_by);
    }

    #[Test]
    public function many_people_per_role_and_one_person_in_several_roles_are_allowed_but_not_the_same_role_twice(): void
    {
        $a = $this->makeStaff('Person A');
        $b = $this->makeStaff('Person B');

        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $a->id, 'role' => 'risk_champion'])->assertRedirect();
        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $b->id, 'role' => 'risk_champion'])->assertRedirect();
        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $a->id, 'role' => 'team_lead'])->assertRedirect();
        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $a->id, 'role' => 'risk_champion'])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->assertSame(3, KriDepartmentContact::query()->count());
        $this->assertSame(3, RiskAuditTrail::query()->where('action_type', 'kri_contact_added')->count(), 'a refused duplicate is not audited');
    }

    #[Test]
    public function an_inactive_user_may_be_added_and_is_flagged_on_the_screen(): void
    {
        $dormant = $this->makeStaff('Dormant Person', [], false);

        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $dormant->id, 'role' => 'unit_head'])->assertRedirect();

        $this->actingAs($this->manager)->get(route('risk.kri.contacts.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments.0.contacts.unit_head.0.is_active', false));
    }

    #[Test]
    public function the_body_is_validated_and_tenant_bound(): void
    {
        $person = $this->makeStaff('Person A');
        $foreignUser = $this->foreignUser();
        $foreignUnit = $this->foreignDepartment();

        $this->addContact([])->assertStatus(422)->assertJsonValidationErrors(['business_unit_id', 'user_id', 'role']);
        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $person->id, 'role' => 'ceo'])->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->addContact(['business_unit_id' => $foreignUnit->id, 'user_id' => $person->id, 'role' => 'team_lead'])->assertStatus(422)->assertJsonValidationErrors(['business_unit_id']);
        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $foreignUser->id, 'role' => 'team_lead'])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->assertSame(0, KriDepartmentContact::withoutGlobalScopes()->count());
    }

    #[Test]
    public function removing_a_contact_deletes_the_row_and_audits_who_was_removed(): void
    {
        $person = $this->makeStaff('Chidi Champion');
        $contact = $this->makeContact($this->unit, $person, KriContactRole::TeamLead);

        $this->actingAs($this->manager)->deleteJson(route('risk.kri.contacts.destroy', $contact))->assertRedirect();

        $this->assertSame(0, KriDepartmentContact::query()->count(), 'a hard delete: the audit row keeps the history');
        $audit = RiskAuditTrail::query()->where('action_type', 'kri_contact_removed')->firstOrFail();
        $this->assertSame('business_unit', $audit->entity_type);
        $this->assertSame('team_lead', $audit->field_changed);
        $this->assertSame("user:{$person->id} Chidi Champion <{$person->email}>", $audit->old_value);
    }

    #[Test]
    public function contacts_are_managed_only_with_the_permission_and_never_across_tenants(): void
    {
        $person = $this->makeStaff('Person A');
        $contact = $this->makeContact($this->unit, $person);
        $nobody = $this->makeStaff('Nobody', ['kri.view']);

        $this->addContact(['business_unit_id' => $this->unit->id, 'user_id' => $person->id, 'role' => 'team_lead'], $nobody)->assertForbidden();
        $this->actingAs($nobody)->deleteJson(route('risk.kri.contacts.destroy', $contact))->assertForbidden();
        $this->actingAs($nobody)->get(route('risk.kri.contacts.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('risk.kri.contacts.users', ['q' => 'Person']))->assertForbidden();

        $theirs = $this->makeContact($this->foreignDepartment(), $this->foreignUser(), KriContactRole::RiskChampion);
        $this->actingAs($this->manager)->deleteJson(route('risk.kri.contacts.destroy', $theirs))->assertNotFound();

        $this->assertSame(1, KriDepartmentContact::query()->count());
        $this->assertSame(1, KriDepartmentContact::withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->count());
    }

    #[Test]
    public function the_screen_flags_a_champion_who_lacks_the_submit_permission_and_lists_every_active_department(): void
    {
        $withPermission = $this->makeStaff('Has Permission', ['kri.submit_return']);
        $without = $this->makeStaff('No Permission');
        $this->makeContact($this->unit, $withPermission);
        $this->makeContact($this->unit, $without);
        $this->makeUnit('Operations', 'OPS');
        $this->makeUnit('Retired', 'RET', null, null, false);

        $this->actingAs($this->manager)->get(route('risk.kri.contacts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Kri/Settings/Contacts')
                ->where('roles', fn ($roles) => collect($roles)->pluck('value')->all() === ['risk_champion', 'team_lead', 'unit_head', 'group_head'])
                ->where('departments', function ($departments) {
                    $byName = collect($departments)->keyBy('name');
                    $champions = collect($byName['Treasury']['contacts']['risk_champion'])->keyBy('name');

                    return $byName['Treasury']['has_champion'] === true
                        && $champions['Has Permission']['has_submit_permission'] === true
                        && $champions['No Permission']['has_submit_permission'] === false
                        && $byName['Operations']['has_champion'] === false
                        && ! $byName->has('Retired');
                }));
    }

    #[Test]
    public function the_user_search_needs_two_characters_returns_at_most_twenty_and_never_another_tenants_users(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeStaff("Searchable {$i}");
        }
        $this->foreignUser('searchable.foreign@example.test')->forceFill(['name' => 'Searchable Foreign'])->save();
        $search = fn (string $q) => $this->actingAs($this->manager)->getJson(route('risk.kri.contacts.users', ['q' => $q]));

        $search('s')->assertOk()->assertExactJson([]);
        $search('')->assertOk()->assertExactJson([]);
        $search('Searchable')->assertOk()->assertJsonCount(20);
        $search('Searchable 7')->assertJsonCount(1)->assertJsonPath('0.name', 'Searchable 7');
        $this->assertSame([], collect($search('foreign')->json())->where('email', 'searchable.foreign@example.test')->all());
        $search('searchable-7@')->assertOk();   // LIKE metacharacters are escaped, not interpreted
        $search('%%')->assertOk()->assertExactJson([]);
    }

    /* ------------------------------------------------------------------ */
    /*  Holidays */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function adding_a_holiday_saves_it_and_audits_it_and_moves_the_first_working_day(): void
    {
        $this->actingAs($this->holidayAdmin)
            ->postJson(route('risk.holidays.store'), ['holiday_date' => '2026-11-02', 'name' => 'Public holiday'])
            ->assertRedirect();

        $holiday = OrganizationHoliday::query()->firstOrFail();
        $this->assertSame('2026-11-02', $holiday->holiday_date->toDateString());
        $this->assertSame('Public holiday', $holiday->name);
        $this->assertSame($this->holidayAdmin->id, (int) $holiday->created_by);
        $this->assertDatabaseHas('risk_audit_trail', ['entity_type' => 'organization_holiday', 'entity_id' => $holiday->id, 'action_type' => 'holiday_added']);

        $this->actingAs($this->holidayAdmin)->get(route('risk.holidays.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Settings/Holidays')
                ->where('year', 2026)
                ->where('holidays.0.date', '2026-11-02')
                ->where('holidays.0.weekday', 'Monday')
                ->where('firstWorkingDays', function ($days) {
                    $nov = collect($days)->firstWhere('month', 11);
                    $oct = collect($days)->firstWhere('month', 10);

                    return $nov['date'] === '2026-11-03' && $nov['moved_by_holiday'] === true
                        && $oct['date'] === '2026-10-01' && $oct['moved_by_holiday'] === false && count($days) === 12;
                }));
    }

    #[Test]
    public function a_weekend_holiday_is_accepted(): void
    {
        $this->actingAs($this->holidayAdmin)
            ->postJson(route('risk.holidays.store'), ['holiday_date' => '2026-11-01', 'name' => 'Sunday holiday'])->assertRedirect();

        $this->assertSame(1, OrganizationHoliday::query()->count());
    }

    #[Test]
    public function the_holiday_body_is_validated(): void
    {
        $post = fn (array $body) => $this->actingAs($this->holidayAdmin)->postJson(route('risk.holidays.store'), $body);

        $post([])->assertStatus(422)->assertJsonValidationErrors(['holiday_date', 'name']);
        $post(['holiday_date' => '02/11/2026', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['holiday_date']);
        $post(['holiday_date' => '2026-02-30', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['holiday_date']);
        $post(['holiday_date' => '2026-11-02', 'name' => str_repeat('x', 151)])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $post(['holiday_date' => '2026-11-02', 'name' => str_repeat('x', 150)])->assertRedirect();
        $post(['holiday_date' => '2026-11-02', 'name' => 'Again'])->assertStatus(422)->assertJsonValidationErrors(['holiday_date']);

        $this->assertSame(1, OrganizationHoliday::query()->count());
    }

    #[Test]
    public function the_same_date_is_allowed_for_another_tenant(): void
    {
        TenantContext::bypass(fn () => OrganizationHoliday::create(['organization_id' => $this->otherOrg->id, 'holiday_date' => '2026-11-02', 'name' => 'Theirs']), 'test fixture');

        $this->actingAs($this->holidayAdmin)
            ->postJson(route('risk.holidays.store'), ['holiday_date' => '2026-11-02', 'name' => 'Ours'])->assertRedirect();

        $this->assertSame(1, OrganizationHoliday::query()->count());
    }

    #[Test]
    public function removing_a_holiday_deletes_it_and_audits_it_and_foreign_ids_are_404(): void
    {
        $holiday = TenantContext::bypass(fn () => OrganizationHoliday::create(['organization_id' => $this->organization->id, 'holiday_date' => '2026-12-25', 'name' => 'Christmas']), 'test fixture');
        $theirs = TenantContext::bypass(fn () => OrganizationHoliday::create(['organization_id' => $this->otherOrg->id, 'holiday_date' => '2026-12-25', 'name' => 'Theirs']), 'test fixture');

        $this->actingAs($this->holidayAdmin)->deleteJson(route('risk.holidays.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->holidayAdmin)->deleteJson(route('risk.holidays.destroy', $holiday))->assertRedirect();

        $this->assertSame(0, OrganizationHoliday::query()->count());
        $this->assertSame(1, OrganizationHoliday::withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->count());
        $audit = RiskAuditTrail::query()->where('action_type', 'holiday_removed')->firstOrFail();
        $this->assertStringContainsString('2026-12-25', (string) $audit->old_value);
        $this->assertStringContainsString('Christmas', (string) $audit->old_value);
    }

    #[Test]
    public function the_holiday_list_needs_its_own_permission(): void
    {
        $nobody = $this->makeStaff('Nobody', ['kri.view', 'kri.manage_department_contacts']);

        $this->actingAs($nobody)->get(route('risk.holidays.index'))->assertForbidden();
        $this->actingAs($nobody)->postJson(route('risk.holidays.store'), ['holiday_date' => '2026-11-02', 'name' => 'x'])->assertForbidden();
        $this->assertSame(0, OrganizationHoliday::query()->count());
    }
}
