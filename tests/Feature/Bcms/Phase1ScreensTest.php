<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\RaciRole;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Findings\FindingService;
use App\Services\Bcms\ProgrammeService;
use App\Services\Bcms\RaciService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 1 screens: that they render, that they are gated, and that the
 * props they hand the page are the ones it reads.
 *
 * Criterion 5 is the one worth reading twice — "a branch manager sees only
 * their branch's processes; a group risk officer sees all, verified by test,
 * BOTH directions". A scoping test that only proves the narrow case passes
 * against a filter that excludes everything.
 */
class Phase1ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $kano;

    private BusinessUnit $lagos;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KAN', 'name' => 'Kano Branch', 'is_active' => true,
        ]);
        $this->lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAG', 'name' => 'Lagos Branch', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, ?BusinessUnit $unit = null): User
    {
        $user = User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $unit?->id,
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        if ($unit !== null) {
            // The product's own assignment pivot. Org scoping resolves through
            // it, not through `users.business_unit_id`, which is the home unit
            // rather than an authority.
            DB::table('business_unit_user')->insert([
                'organization_id' => $this->organization->id,
                'user_id' => $user->id,
                'business_unit_id' => $unit->id,
                'includes_descendants' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function process(string $code, ?BusinessUnit $unit): Process
    {
        return Process::query()->create([
            'code' => $code, 'name' => "Process {$code}",
            'business_unit_id' => $unit?->id, 'status' => 'active', 'criticality_tier' => 2,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — org scoping, both directions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_branch_manager_sees_only_their_own_branchs_processes(): void
    {
        $this->process('BCP-KAN', $this->kano);
        $this->process('BCP-LAG', $this->lagos);
        $this->process('BCP-GRP', null);

        $manager = $this->userWith(['bcms.process.view'], 'kano@khb.test', $this->kano);

        $this->actingAs($manager)
            ->get(route('bcms.processes.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $codes = array_column($page->toArray()['props']['processes']['data'], 'code');

                sort($codes);

                // Their branch, plus the group-level process that belongs to no
                // branch. A group BCP every branch must follow is not somebody
                // else's, and excluding nulls would hide it.
                $this->assertSame(['BCP-GRP', 'BCP-KAN'], $codes);
            });
    }

    #[Test]
    public function a_group_risk_officer_sees_every_branch(): void
    {
        $this->process('BCP-KAN', $this->kano);
        $this->process('BCP-LAG', $this->lagos);
        $this->process('BCP-GRP', null);

        // The product's existing "sees the whole estate" grant, held by the
        // Head of ORM, the CRO and internal audit. BCMS does not invent a
        // second one (Orchestration §5).
        $officer = $this->userWith(['bcms.process.view', 'rcsa_scope.all_units'], 'group@khb.test');

        $this->actingAs($officer)
            ->get(route('bcms.processes.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $codes = array_column($page->toArray()['props']['processes']['data'], 'code');

                sort($codes);

                $this->assertSame(['BCP-GRP', 'BCP-KAN', 'BCP-LAG'], $codes);
            });
    }

    #[Test]
    public function a_user_assigned_to_nothing_sees_only_organisation_level_processes(): void
    {
        // An empty assignment list is "assigned to nothing", never
        // "everything". Failing open here is the classic way RBAC becomes
        // theatre, on exactly the accounts nobody has configured.
        $this->process('BCP-KAN', $this->kano);
        $this->process('BCP-GRP', null);

        $user = $this->userWith(['bcms.process.view'], 'nobody@khb.test');

        $this->actingAs($user)
            ->get(route('bcms.processes.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $codes = array_column($page->toArray()['props']['processes']['data'], 'code');

                $this->assertSame(['BCP-GRP'], $codes);
            });
    }

    /* ------------------------------------------------------------------ */
    /*  The screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_programme_screen_offers_a_creation_form_when_there_is_no_programme(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.programme.manage'], 'pm@khb.test');

        $this->actingAs($user)
            ->get(route('bcms.programme.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Programme/Index')
                ->where('programme', null)
                ->where('can.manage', true)
            );
    }

    #[Test]
    public function the_programme_screen_carries_the_raci_gap_report(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.process.view'], 'pm@khb.test');

        $programme = app(ProgrammeService::class)->create([
            'name' => 'Programme', 'year' => (int) now()->year, 'scope_statement' => 'Everything.',
        ]);

        $withOwner = $this->process('BCP-001', $this->kano);
        $this->process('BCP-002', $this->kano);

        app(RaciService::class)->assign($withOwner, $user->id, RaciRole::Accountable);

        $this->actingAs($user)
            ->get(route('bcms.programme.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('programme.name', 'Programme')
                ->where('raci_gaps.total', 2)
                ->has('raci_gaps.without_accountable', 1)
            );
    }

    #[Test]
    public function a_programme_is_created_approved_and_activated_through_the_screen(): void
    {
        $owner = $this->userWith(['bcms.view', 'bcms.programme.manage'], 'owner@khb.test');
        $approver = $this->userWith(['bcms.view', 'bcms.programme.approve'], 'chair@khb.test');

        $this->actingAs($owner)->post(route('bcms.programme.store'), [
            'name' => 'BCMS Programme', 'year' => (int) now()->year,
            'scope_statement' => 'Head office and eight branches.',
            'owner_id' => $owner->id,
        ])->assertRedirect();

        $programme = Programme::query()->firstOrFail();

        // The owner may not approve their own programme, and the screen says
        // why rather than returning a 403 that suggests the account is wrong.
        $this->actingAs($owner)->post(route('bcms.programme.approve', $programme))->assertForbidden();

        $this->actingAs($approver)->post(route('bcms.programme.approve', $programme))->assertRedirect();
        $this->assertSame('approved', $programme->refresh()->status);

        $this->actingAs($approver)->post(route('bcms.programme.activate', $programme))->assertRedirect();
        $this->assertSame('active', $programme->refresh()->status);
    }

    #[Test]
    public function excluding_something_from_scope_without_a_reason_is_refused(): void
    {
        $user = $this->userWith(['bcms.view', 'bcms.programme.manage'], 'pm@khb.test');

        $programme = app(ProgrammeService::class)->create([
            'name' => 'Programme', 'year' => (int) now()->year, 'scope_statement' => 'Everything.',
        ]);

        // Clause 4.3 asks for the boundary to be justified.
        $this->actingAs($user)->post(route('bcms.programme.scope.store', $programme), [
            'scopable_type' => 'business_unit',
            'scopable_id' => $this->lagos->id,
            'in_scope' => false,
        ])->assertSessionHasErrors('rationale');

        $this->actingAs($user)->post(route('bcms.programme.scope.store', $programme), [
            'scopable_type' => 'business_unit',
            'scopable_id' => $this->lagos->id,
            'in_scope' => false,
            'rationale' => 'The Lagos branch is managed under a separate programme.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_policy_screen_renders_its_version_chain_and_attestations(): void
    {
        $author = $this->userWith(['bcms.plan.view', 'bcms.plan.manage'], 'author@khb.test');
        $approver = $this->userWith(['bcms.plan.view', 'bcms.plan.approve', 'bcms.programme.approve'], 'board@khb.test');

        $this->actingAs($author)->post(route('bcms.policy.store'), ['title' => 'BC Policy'])->assertRedirect();

        $policy = \App\Models\Bcms\Plan::query()->firstOrFail();

        $this->actingAs($approver)->post(route('bcms.policy.approve', $policy))->assertRedirect();
        $this->actingAs($approver)->post(route('bcms.policy.attest', $policy))->assertRedirect();

        $this->actingAs($approver)
            ->get(route('bcms.policy.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Policy/Index')
                ->where('current.version', '1.0')
                ->has('versions', 1)
                ->has('versions.0.attestations', 1)
                ->where('versions.0.immutable', true)
            );
    }

    #[Test]
    public function an_approved_policy_cannot_be_edited_through_the_screen(): void
    {
        $author = $this->userWith(['bcms.plan.view', 'bcms.plan.manage'], 'author@khb.test');
        $approver = $this->userWith(['bcms.plan.approve'], 'board@khb.test');

        $this->actingAs($author)->post(route('bcms.policy.store'), ['title' => 'BC Policy']);
        $policy = \App\Models\Bcms\Plan::query()->firstOrFail();
        $this->actingAs($approver)->post(route('bcms.policy.approve', $policy));

        $this->actingAs($author)
            ->put(route('bcms.policy.update', $policy), ['title' => 'Rewritten'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('BC Policy', $policy->refresh()->title);
    }

    #[Test]
    public function the_findings_register_renders_with_its_filters_and_summary(): void
    {
        $user = $this->userWith(['bcms.finding.view', 'bcms.finding.manage'], 'risk@khb.test');

        app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Nonconformity,
            'The documented diversity does not exist.',
            null,
            ['iso_clause_ref' => IsoClauseRef::Iso22301_8_3->value, 'severity' => 'high'],
            $user->id,
        );

        $this->actingAs($user)
            ->get(route('bcms.findings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Findings/Index')
                ->has('findings.data', 1)
                ->where('summary.open', 1)
                ->where('summary.nonconformities_open', 1)
                ->has('options.sources', count(FindingSource::cases()))
                ->where('can.manage', true)
                ->where('can.verify', false)
            );
    }

    #[Test]
    public function the_register_filters_to_overdue_actions(): void
    {
        $user = $this->userWith(['bcms.finding.view', 'bcms.finding.manage'], 'risk@khb.test');

        $onTime = app(FindingService::class)->raise(FindingSource::Audit, FindingClassification::Improvement, 'On time', null, [], $user->id);
        $late = app(FindingService::class)->raise(FindingSource::Audit, FindingClassification::Improvement, 'Late', null, [], $user->id);

        $actions = app(CorrectiveActionService::class);
        $actions->create($onTime, 'Soon', ['owner_id' => $user->id, 'due_date' => now()->addMonth()->toDateString()], $user->id);
        $overdue = $actions->create($late, 'Overdue', ['owner_id' => $user->id, 'due_date' => now()->subWeek()->toDateString()], $user->id);
        $actions->sweep();

        $this->actingAs($user)
            ->get(route('bcms.findings.index', ['due' => 'overdue']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('findings.data', 1)
                ->where('findings.data.0.reference', $late->reference)
                ->where('findings.data.0.actions.0.is_overdue', true)
            );

        $this->assertSame('overdue', $overdue->refresh()->status->value);
    }

    #[Test]
    public function verifying_a_corrective_action_needs_the_separate_grant(): void
    {
        $doer = $this->userWith(['bcms.finding.view', 'bcms.finding.manage'], 'doer@khb.test');
        $verifier = $this->userWith(['bcms.finding.view', 'bcms.finding.verify'], 'verifier@khb.test');

        $finding = app(FindingService::class)->raise(FindingSource::Audit, FindingClassification::Improvement, 'Something', null, [], $doer->id);

        $this->actingAs($doer)->post(route('bcms.actions.store', $finding), [
            'title' => 'Fix it', 'owner_id' => $doer->id,
        ])->assertRedirect();

        $action = CorrectiveAction::query()->firstOrFail();

        $this->actingAs($doer)->post(route('bcms.actions.complete', $action))->assertRedirect();

        // Holding `finding.manage` is not enough to verify.
        $this->actingAs($doer)->post(route('bcms.actions.verify', $action))->assertForbidden();

        $this->actingAs($verifier)->post(route('bcms.actions.verify', $action))->assertRedirect();
        $this->assertSame('verified', $action->refresh()->status->value);
    }

    #[Test]
    public function the_person_who_did_the_work_cannot_verify_it_even_holding_the_grant(): void
    {
        // The permission answers "may this person verify anything"; the service
        // answers "may they verify THIS". Both are needed.
        $doer = $this->userWith(['bcms.finding.view', 'bcms.finding.manage', 'bcms.finding.verify'], 'both@khb.test');

        $finding = app(FindingService::class)->raise(FindingSource::Audit, FindingClassification::Improvement, 'Something', null, [], $doer->id);
        $action = app(CorrectiveActionService::class)->create($finding, 'Fix it', ['owner_id' => $doer->id], $doer->id);
        app(CorrectiveActionService::class)->complete($action, $doer->id);

        $this->actingAs($doer)
            ->post(route('bcms.actions.verify', $action))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('completed', $action->refresh()->status->value);
    }

    #[Test]
    public function raising_a_nonconformity_without_a_clause_is_refused_with_an_explanation(): void
    {
        $user = $this->userWith(['bcms.finding.view', 'bcms.finding.manage'], 'risk@khb.test');

        $this->actingAs($user)
            ->post(route('bcms.findings.store'), [
                'source' => 'gap_analysis',
                'classification' => 'nonconformity',
                'description' => 'Something is wrong',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Finding::query()->count());
    }

    #[Test]
    public function the_process_catalogue_exports_the_columns_the_importer_reads(): void
    {
        $user = $this->userWith(['bcms.process.view'], 'risk@khb.test');
        $this->process('BCP-001', $this->kano);

        $response = $this->actingAs($user)->get(route('bcms.processes.export'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $header = str_getcsv(strtok($csv, "\n"));
        $this->assertSame(\App\Services\Bcms\ProcessCatalogueService::HEADERS, $header);
        $this->assertStringContainsString('BCP-001', $csv);
    }

    #[Test]
    public function a_critical_service_needs_a_justification_at_the_form_as_well_as_the_importer(): void
    {
        $user = $this->userWith(['bcms.process.view', 'bcms.process.manage'], 'risk@khb.test');

        $this->actingAs($user)->post(route('bcms.processes.store'), [
            'code' => 'BCP-001', 'name' => 'Payments', 'is_critical_service' => true,
        ])->assertSessionHasErrors('critical_service_justification');

        $this->actingAs($user)->post(route('bcms.processes.store'), [
            'code' => 'BCP-001', 'name' => 'Payments', 'is_critical_service' => true,
            'critical_service_justification' => 'It is part of the national payments system.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_live_sections_replaced_their_shells_and_kept_their_route_names(): void
    {
        // The `live` flag exists so a phase can swap a shell for a real screen
        // without moving a URL, a permission or a bookmark.
        $user = $this->userWith([
            'bcms.view', 'bcms.process.view', 'bcms.finding.view', 'bcms.incident.view',
        ], 'risk@khb.test');

        $this->actingAs($user)->get(route('bcms.programme.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Bcms/Programme/Index'));
        $this->actingAs($user)->get(route('bcms.processes.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Bcms/Processes/Index'));
        $this->actingAs($user)->get(route('bcms.findings.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Bcms/Findings/Index'));

        // A section whose phase has not landed still shows the shell. The
        // example has to be one that has not landed, or this half asserts
        // nothing: `calendar` was it until Phase 4, `call-trees` until Phase 6,
        // `emns` until Phase 7, and `incidents` is Phase 10's.
        $this->actingAs($user)->get(route('bcms.incidents.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Bcms/Section'));
    }
}
