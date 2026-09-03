<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migration Phase 1 — enrolment never sends the shared secret anywhere.
 */
class MfaSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['features.mfa_totp' => true]);
        cache()->flush();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_setup_screen_carries_an_otpauth_uri_and_names_no_external_host(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->get('/mfa/setup')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/MfaSetup')
            ->has('secret')
            ->where('account', $user->email)
            ->where('otpauthUri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/')
                && str_contains($uri, 'secret='.$page->toArray()['props']['secret'])));

        // Every host in the response must be this deployment. The page JSON
        // escapes slashes, so both spellings are stripped before looking.
        $html = $response->getContent();
        $appUrl = rtrim((string) config('app.url'), '/');
        $html = str_replace([$appUrl, str_replace('/', '\/', $appUrl)], '', $html);

        $this->assertDoesNotMatchRegularExpression('#https?:(\\\\/|/){2}#', $html, 'The enrolment screen references an external host.');
        $this->assertStringNotContainsString('qrserver', $html);
    }

    #[Test]
    public function the_right_code_enables_mfa_from_the_secret_held_in_the_session(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/mfa/setup')->assertOk();
        $secret = session('mfa_setup_secret');
        $this->assertIsString($secret);

        $this->actingAs($user)
            ->post('/mfa/enable', ['code' => Totp::at($secret, time())])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('mfa_verified', true);

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertSame($secret, $user->mfa_secret);
    }

    #[Test]
    public function a_wrong_code_leaves_the_account_unenrolled(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/mfa/setup');

        $this->actingAs($user)
            ->from('/mfa/setup')
            ->post('/mfa/enable', ['code' => '000000'])
            ->assertRedirect('/mfa/setup')
            ->assertSessionHasErrors('code');

        $this->assertFalse((bool) $user->fresh()->mfa_enabled);
    }

    private function user(): User
    {
        $org = Organization::create([
            'name' => 'Setup Bank PLC',
            'short_name' => 'SETB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($org->id);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Setup User',
            'email' => 'setup@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole('risk-manager');

        return $user;
    }
}
