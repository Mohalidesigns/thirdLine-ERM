<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\IntakeService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Intake Queue's Approve and Reject actions, exercised the way the SCREEN
 * exercises them — read the Inertia props, then post to the URL those props
 * carry — rather than by hand-building the URL from `$engagement->uuid` or
 * `$engagement->id`.
 *
 * WHY THIS TEST EXISTS. `Engagement` route-binds on its `uuid`
 * (`HasTprmUuid`), but `IntakeController::index()` used to ship the queue
 * row's NUMERIC `id` and nothing else, so `Index.jsx` built
 * `tryRoute('tprm.intake.approve', engagementId)` from that numeric id and
 * posted to a URL that 404s — verified over HTTP against a logged-in
 * session. No existing test caught it, because every other test in this
 * suite builds the URL with the server-side `route()` helper, which resolves
 * the model's OWN route key regardless of what the frontend does. That is
 * precisely why this test reads `queue.data[0].approve_url` out of the
 * response instead.
 */
class IntakeQueueRouteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $approver;

    private ThirdParty $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Interlink Systems Limited',
            'slug' => Str::random(10),
            'entity_type' => 'company',
            'status' => 'approved_supplier',
        ]);

        $this->approver = $this->userWith(
            ['tprm.view', 'tprm.intake.approve'],
            'approver@khb.test',
            'tprm-approver',
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_queue_ships_approve_and_reject_urls_bound_to_the_engagements_own_route_key(): void
    {
        $engagement = $this->submittedEngagement();

        $this->actingAs($this->approver)
            ->get(route('tprm.intake.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($engagement) {
                $page->component('Tprm/Intake/Index')->has('queue.data', 1);

                $row = $page->toArray()['props']['queue']['data'][0];

                $this->assertSame(
                    route('tprm.intake.approve', $engagement),
                    $row['approve_url'],
                );
                $this->assertSame(
                    route('tprm.intake.reject', $engagement),
                    $row['reject_url'],
                );

                // The defect this test exists to catch: the numeric id the
                // row also carries (for React keys) is NOT what the route
                // resolves against.
                $this->assertNotSame((string) $engagement->getKey(), (string) $engagement->uuid);
                $this->assertStringContainsString((string) $engagement->uuid, $row['approve_url']);
                $this->assertStringNotContainsString(
                    '/intake/'.$engagement->getKey().'/',
                    $row['approve_url'],
                );
            });
    }

    #[Test]
    public function posting_to_the_props_approve_url_approves_the_intake(): void
    {
        $engagement = $this->submittedEngagement();

        $approveUrl = $this->actingAs($this->approver)
            ->get(route('tprm.intake.index'))
            ->viewData('page')['props']['queue']['data'][0]['approve_url'];

        $this->actingAs($this->approver)
            ->post($approveUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(EngagementStatus::IntakeApproved, $engagement->fresh()->status);

        $audit = AuditLog::where('auditable_id', $engagement->getKey())
            ->where('auditable_type', Engagement::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(EngagementStatus::IntakeApproved->value, $audit->after['status'] ?? null);
    }

    #[Test]
    public function posting_to_the_props_reject_url_returns_the_intake_to_draft_with_its_reason(): void
    {
        $engagement = $this->submittedEngagement();

        $rejectUrl = $this->actingAs($this->approver)
            ->get(route('tprm.intake.index'))
            ->viewData('page')['props']['queue']['data'][0]['reject_url'];

        $this->actingAs($this->approver)
            ->post($rejectUrl, [
                'reason_code' => 'insufficient_controls',
                'rationale' => 'No ISO 27001 and no SOC 2 on file.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(EngagementStatus::Draft, $engagement->fresh()->status);

        $audit = AuditLog::where('event', 'intake_rejected')
            ->where('auditable_id', $engagement->getKey())
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('insufficient_controls', $audit->after['reason_code']);
    }

    #[Test]
    public function a_user_of_another_tenant_posting_to_the_same_shipped_approve_url_gets_404(): void
    {
        $engagement = $this->submittedEngagement();

        $approveUrl = $this->actingAs($this->approver)
            ->get(route('tprm.intake.index'))
            ->viewData('page')['props']['queue']['data'][0]['approve_url'];

        $foreignOrg = Organization::create([
            'name' => 'Another Bank PLC', 'short_name' => 'ABP',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $foreignUser = User::create([
            'name' => 'Foreign Approver', 'email' => 'approver@abp.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $foreignOrg->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-foreign-approver', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.intake.approve', 'web'));
        $role->givePermissionTo(Permission::findOrCreate('tprm.view', 'web'));
        $foreignUser->assignRole($role);

        $this->actingAs($foreignUser)
            ->post($approveUrl)
            ->assertNotFound();

        $this->assertSame(
            EngagementStatus::IntakeSubmitted,
            $engagement->fresh()->status,
            'A cross-tenant post to the shipped approve_url must not approve the engagement.'
        );
    }

    #[Test]
    public function a_user_of_another_tenant_posting_to_the_same_shipped_reject_url_gets_404(): void
    {
        $engagement = $this->submittedEngagement();

        $rejectUrl = $this->actingAs($this->approver)
            ->get(route('tprm.intake.index'))
            ->viewData('page')['props']['queue']['data'][0]['reject_url'];

        $foreignOrg = Organization::create([
            'name' => 'Third Bank PLC', 'short_name' => 'TBP',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $foreignUser = User::create([
            'name' => 'Foreign Rejecter', 'email' => 'rejecter@tbp.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $foreignOrg->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-foreign-rejecter', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.intake.approve', 'web'));
        $role->givePermissionTo(Permission::findOrCreate('tprm.view', 'web'));
        $foreignUser->assignRole($role);

        $this->actingAs($foreignUser)
            ->post($rejectUrl, [
                'reason_code' => 'insufficient_controls',
                'rationale' => 'Not our engagement to reject.',
            ])
            ->assertNotFound();

        $this->assertSame(
            EngagementStatus::IntakeSubmitted,
            $engagement->fresh()->status,
            'A cross-tenant post to the shipped reject_url must not touch the engagement.'
        );
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->ucfirst()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function submittedEngagement(): Engagement
    {
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->firstOrFail();

        $result = app(IntakeService::class)->submit(
            [
                'organization_id' => $this->organization->id,
                'third_party_id' => $this->vendor->id,
                'name' => 'Back office support',
                'engagement_type' => 'outsourcing',
                'currency' => 'NGN',
            ],
            [$hr->id],
            [
                'A1' => 'internal', 'A2' => '1k_100k', 'A3' => 'domestic', 'A5' => 'read_only',
                'A7' => 'standard', 'A8' => 'over_72h', 'A10' => ['cbn_cyber'],
                'A12' => 'many', 'A13' => 'under_1m', 'A14' => 'under_10m',
            ],
        );

        return $result->engagement;
    }
}
