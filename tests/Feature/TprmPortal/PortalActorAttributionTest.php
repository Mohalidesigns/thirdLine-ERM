<?php

namespace Tests\Feature\TprmPortal;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Document;
use App\Models\Tprm\Incident;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Portal\PortalAuthService;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Who a TPRM audit row says wrote it (ADR 0028).
 *
 * A vendor authenticates on the `tprm-portal` guard, which has its own
 * session, so on a portal request the default guard's user is null and the
 * trait used to seal every model write the vendor caused as `system`. A
 * vendor-reported incident therefore got one row by `system` and one by
 * `portal` for the same act. These go through the real routes.
 */
class PortalActorAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private PortalUser $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        Storage::fake('local');

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $this->vendor = TenantContext::actingAs($this->bank->id, function (): PortalUser {
            $thirdParty = ThirdParty::create([
                'organization_id' => $this->bank->id,
                'legal_name' => 'Cloudspan Nigeria Limited',
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
            ]);

            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $thirdParty->id,
                'email' => 'ops@cloudspan.test',
                'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_method' => PortalUser::METHOD_TOTP,
                'mfa_secret' => Totp::generateSecret(),
                'mfa_enabled' => true,
            ])->save();

            return $user;
        });
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function every_row_a_vendor_reported_incident_leaves_says_portal_and_names_the_vendor(): void
    {
        $this->signIn();

        $this->post(route('tprm-portal.incidents.report'), [
            'title' => 'Unauthorised access to a support mailbox',
            'type' => 'data_breach',
            'description' => 'A mailbox was read by someone who should not have.',
            'detected_at' => now()->subHours(3)->toDateTimeString(),
            'personal_data_involved' => true,
        ])->assertSessionHasNoErrors();

        $incident = Incident::query()->withoutGlobalScopes()->where('third_party_id', $this->vendor->third_party_id)->firstOrFail();
        $rows = $this->rowsFor(Incident::class, $incident->getKey());

        $events = $rows->pluck('event')->all();
        $this->assertContains('created', $events, 'the trait must have written its own row');
        $this->assertContains('incident_reported_by_vendor', $events);

        foreach ($rows as $row) {
            $this->assertSame(AuditLog::ACTOR_PORTAL, $row->actor_type, "the {$row->event} row");
            $this->assertSame($this->vendor->id, (int) $row->actor_id, "the {$row->event} row");
            $this->assertNull($row->actor_label, "the {$row->event} row");
            $this->assertTrue($row->isIntact(), "the {$row->event} row");
        }
    }

    #[Test]
    public function a_vendors_evidence_upload_is_recorded_as_the_vendor(): void
    {
        $this->signIn();

        $this->post(route('tprm-portal.documents.upload'), [
            'file' => UploadedFile::fake()->create('iso27001.pdf', 120, 'application/pdf'),
            'title' => 'ISO 27001 certificate',
            'document_type_id' => null,
        ])->assertSessionHasNoErrors();

        $document = Document::query()->withoutGlobalScopes()->where('owner_id', $this->vendor->third_party_id)->firstOrFail();
        $created = $this->rowsFor(Document::class, $document->getKey())->firstWhere('event', 'created');

        $this->assertNotNull($created);
        $this->assertSame(AuditLog::ACTOR_PORTAL, $created->actor_type);
        $this->assertSame($this->vendor->id, (int) $created->actor_id);
        $this->assertNull($created->actor_label);
        $this->assertTrue($created->isIntact());
    }

    #[Test]
    public function an_internal_user_is_still_recorded_as_a_user_with_their_name(): void
    {
        $incident = $this->incident();

        $user = $this->officer();

        $this->actingAs($user)
            ->post(route('tprm.incidents.assess', $incident->uuid))
            ->assertRedirect();

        $row = $this->rowsFor(Incident::class, $incident->getKey())->where('event', 'updated')->last();

        $this->assertNotNull($row, 'assessing the clocks must change and audit the incident');
        $this->assertSame(AuditLog::ACTOR_USER, $row->actor_type);
        $this->assertSame($user->id, (int) $row->actor_id);
        $this->assertSame($user->name, $row->actor_label);
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function a_write_with_no_request_behind_it_is_recorded_as_the_system(): void
    {
        $incident = $this->incident();

        Artisan::command('adr0028:touch {id}', function (string $id): void {
            TenantContext::set((int) Incident::query()->withoutGlobalScopes()->findOrFail($id)->organization_id);
            Incident::query()->findOrFail($id)->update(['title' => 'Retitled by a console command']);
        });

        $this->assertSame(0, Artisan::call('adr0028:touch', ['id' => $incident->getKey()]));

        $row = $this->rowsFor(Incident::class, $incident->getKey())->where('event', 'updated')->last();

        $this->assertNotNull($row);
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $row->actor_type);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->actor_label);
        $this->assertTrue($row->isIntact());
    }

    #[Test]
    public function the_vendor_wins_when_both_guards_are_authenticated(): void
    {
        $incident = $this->incident();
        $colleague = $this->officer();

        $this->actingAs($colleague, 'web');
        Auth::guard('tprm-portal')->setUser($this->vendor);

        try {
            TenantContext::actingAs($this->bank->id, fn () => Incident::query()->findOrFail($incident->getKey())
                ->update(['title' => 'Retitled with two guards live']));
        } finally {
            Auth::forgetGuards();
        }

        $row = $this->rowsFor(Incident::class, $incident->getKey())->where('event', 'updated')->last();

        $this->assertNotNull($row);
        $this->assertSame(AuditLog::ACTOR_PORTAL, $row->actor_type, 'portal is resolved first: a vendor must never be recorded as a colleague');
        $this->assertSame($this->vendor->id, (int) $row->actor_id);
        $this->assertNull($row->actor_label);
    }

    #[Test]
    public function sign_in_and_sign_out_name_the_vendor_while_a_failed_attempt_does_not(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $this->vendor->email,
            'password' => 'not-the-password',
        ]);

        $this->signIn();
        $this->post(route('tprm-portal.logout'));

        $rows = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', PortalUser::class)->where('auditable_id', $this->vendor->id)
            ->orderBy('id')->get();

        foreach (['portal_signed_in', 'portal_signed_out'] as $event) {
            $row = $rows->firstWhere('event', $event);
            $this->assertNotNull($row, $event);
            $this->assertSame(AuditLog::ACTOR_PORTAL, $row->actor_type);
            $this->assertSame($this->vendor->id, (int) $row->actor_id, $event.' names the vendor (A3)');
            $this->assertNull($row->actor_label);
            $this->assertSame($this->vendor->id, $row->after['portal_user_id']);
            $this->assertArrayNotHasKey('email', $row->after);
        }

        foreach ($rows->where('actor_type', AuditLog::ACTOR_PORTAL)->whereNotIn('event', ['portal_signed_in', 'portal_signed_out']) as $row) {
            $this->assertNull($row->actor_id, $row->event.' is pre-authentication: nobody has proven who they are');
            $this->assertNull($row->actor_label, $row->event.' carries no label (ADR 0028 section 2)');
        }
    }

    #[Test]
    public function identity_and_trust_profile_rows_name_the_vendor_by_id_only(): void
    {
        TenantContext::actingAs($this->bank->id, function (): void {
            $identity = app(VendorIdentityService::class);
            $identity->claim($this->vendor);
            $profile = $identity->profileFor($this->vendor);
            app(TrustProfileService::class)->publish($profile, $this->vendor);
        });

        $rows = AuditLog::query()->withoutGlobalScopes()
            ->whereIn('event', ['portal_identity_claimed', 'portal_trust_profile_published'])->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(AuditLog::ACTOR_PORTAL, $row->actor_type);
            $this->assertSame($this->vendor->id, (int) $row->actor_id);
            $this->assertNull($row->actor_label);
            $this->assertArrayNotHasKey('email', $row->after);
            $this->assertTrue($row->isIntact());
        }
    }

    #[Test]
    public function a_lockout_is_a_pre_authentication_event_and_names_no_actor(): void
    {
        $this->lockOut();

        $row = $this->lockedRow();

        $this->assertSame(AuditLog::ACTOR_PORTAL, $row->actor_type);
        $this->assertNull($row->actor_id, 'an attempt must not be pinned on its target (ADR 0028 section 2)');
        $this->assertNull($row->actor_label);
        $this->assertSame($this->vendor->id, $row->after['portal_user_id'], 'the subject is named in the payload, not as the actor');
    }

    #[Test]
    public function a_pre_authentication_event_never_takes_its_actor_from_the_portal_guard(): void
    {
        // A3: the id is passed explicitly by the two post-authentication call
        // sites. Even with a vendor resolved on the guard, a lockout stays null.
        Auth::guard('tprm-portal')->setUser($this->vendor);

        try {
            $this->lockOut();
        } finally {
            Auth::forgetGuards();
        }

        $this->assertNull($this->lockedRow()->actor_id);
    }

    private function lockOut(): void
    {
        TenantContext::actingAs($this->bank->id, function (): void {
            for ($i = 0; $i < PortalUser::MAX_ATTEMPTS; $i++) {
                app(PortalAuthService::class)->attempt($this->bank->id, $this->vendor->email, 'wrong-password-'.$i);
            }
        });
    }

    private function lockedRow(): AuditLog
    {
        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('event', 'portal_account_locked')->where('auditable_id', $this->vendor->id)->first();

        $this->assertNotNull($row, 'the lockout must be audited');

        return $row;
    }

    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Support\Collection<int, AuditLog> */
    private function rowsFor(string $class, int $id)
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', $class)
            ->where('auditable_id', $id)
            ->orderBy('id')
            ->get();
    }

    private function incident(): Incident
    {
        return TenantContext::actingAs($this->bank->id, function (): Incident {
            $incident = Incident::create([
                'organization_id' => $this->bank->id,
                'third_party_id' => $this->vendor->third_party_id,
                'engagement_ids' => [],
                'reference' => 'TPI-2026-'.Str::upper(Str::random(4)),
                'type' => 'data_breach',
                'title' => 'Fixture incident',
                'description' => 'Recorded for ADR 0028.',
                'detected_at' => now()->subHours(6),
                'personal_data_involved' => true,
                'customer_impact' => true,
            ]);

            $incident->forceFill(['reported_to_us_at' => now(), 'reported_by' => Incident::SOURCE_PORTAL])->save();

            return $incident->refresh();
        });
    }

    private function officer(): User
    {
        $user = User::create([
            'name' => 'Compliance Officer', 'email' => 'officer@lub.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-incident-'.Str::random(6), 'web');

        foreach (['tprm.incident.view', 'tprm.incident.manage'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user;
    }

    private function signIn(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $this->vendor->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $this->post(route('tprm-portal.mfa.verify'), [
            'code' => Totp::at((string) $this->vendor->mfa_secret, time()),
        ]);
    }
}
