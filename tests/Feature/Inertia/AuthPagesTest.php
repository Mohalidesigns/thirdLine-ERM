<?php

namespace Tests\Feature\Inertia;

use App\Presenters\NavPresenter;
use App\Support\Migration\Ported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 1 — every page the phase ports renders through Inertia
 * with the props its React component needs, and the Blade views are gone.
 */
class AuthPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['dashboard.view', 'notification.view', 'search.view', 'my.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['dashboard.view', 'notification.view', 'search.view', 'my.view']);

        cache()->flush();
    }

    #[Test]
    public function the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('canResetPassword', true)
                ->where('ssoAvailable', false)
                ->has('status'));
    }

    #[Test]
    public function a_failed_login_comes_back_to_the_login_page_with_the_email(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => $this->actor->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->get('/login')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('old.email', $this->actor->email)
                ->has('errors.email'));
    }

    #[Test]
    public function the_password_reset_pages(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword')->has('status'));

        $this->get('/reset-password/abc123?email=risk@example.test')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'abc123')
                ->where('email', 'risk@example.test'));
    }

    #[Test]
    public function the_reset_flow_still_works_end_to_end(): void
    {
        $this->post('/forgot-password', ['email' => $this->actor->email])->assertRedirect();

        $token = cache()->get('password_reset_'.$this->actor->email);
        $this->assertIsString($token);

        $this->post('/reset-password', [
            'email' => $this->actor->email,
            'token' => $token,
            'password' => 'Str0ng-Passw0rd!!',
            'password_confirmation' => 'Str0ng-Passw0rd!!',
        ])->assertRedirect('/login');

        $this->post('/login', ['email' => $this->actor->email, 'password' => 'Str0ng-Passw0rd!!'])
            ->assertRedirect('/risk/dashboard');
        $this->assertAuthenticatedAs($this->actor);
    }

    #[Test]
    public function the_profile_page_and_its_two_forms(): void
    {
        $this->actingAs($this->actor)->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.name', $this->actor->name)
                ->where('profile.email', $this->actor->email)
                ->where('profile.mfa_enabled', false)
                ->has('mfaAvailable'));

        $this->actingAs($this->actor)
            ->patch('/profile', ['name' => 'Renamed Officer', 'job_title' => 'Head, Operational Risk', 'email' => 'ignored@example.test'])
            ->assertRedirect();

        $this->actor->refresh();
        $this->assertSame('Renamed Officer', $this->actor->name);
        $this->assertSame('Head, Operational Risk', $this->actor->job_title);
        $this->assertNotSame('ignored@example.test', $this->actor->email, 'Email is not editable from the profile.');

        $this->actingAs($this->actor)
            ->from('/profile')
            ->put('/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->actor)
            ->put('/profile/password', ['current_password' => 'password', 'password' => 'Str0ng-Passw0rd!!', 'password_confirmation' => 'Str0ng-Passw0rd!!'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Str0ng-Passw0rd!!', $this->actor->fresh()->password));
    }

    #[Test]
    public function the_notifications_page_and_the_polled_count(): void
    {
        DB::table('notifications_log')->insert([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'channel' => 'database',
            'type' => 'approval_request',
            'subject' => 'Approve the Q3 register',
            'body' => 'Please review.',
            'status' => 'sent',
            'notification_category' => 'approval',
            'priority' => 'high',
            'metadata' => json_encode(['entity_type' => 'risk', 'entity_id' => 7]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->actor)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->where('filter', 'all')
                ->where('unreadCount', 1)
                ->has('notifications.data', 1)
                ->where('notifications.data.0.subject', 'Approve the Q3 register')
                ->where('notifications.data.0.is_unread', true)
                ->where('notifications.data.0.metadata.entity_id', 7));

        $this->actingAs($this->actor)->get('/notifications?filter=unread')
            ->assertInertia(fn (Assert $page) => $page->where('filter', 'unread')->has('notifications.data', 1));

        $this->actingAs($this->actor)->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 1]);

        $this->actingAs($this->actor)->post('/notifications/read-all')->assertRedirect();

        $this->actingAs($this->actor)->getJson('/notifications/unread-count')->assertJson(['unread_count' => 0]);
    }

    #[Test]
    public function the_search_page(): void
    {
        $this->actingAs($this->actor)->get('/search')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Search/Index')->where('term', '')->where('results', []));

        $this->actingAs($this->actor)->get('/search?q=Naira')
            ->assertInertia(fn (Assert $page) => $page->component('Search/Index')->where('term', 'Naira')->has('results'));
    }

    #[Test]
    public function the_blade_views_are_gone_and_the_old_controller_with_them(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/auth'));
        $this->assertFileDoesNotExist(resource_path('views/notifications/index.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/search/index.blade.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Auth/AuthController.php'));
    }

    #[Test]
    public function every_ported_route_exists_and_the_nav_marks_exactly_those_as_inertia(): void
    {
        foreach (Ported::ROUTES as $name) {
            $this->assertTrue(Route::has($name), "Ported::ROUTES names [{$name}], which does not exist.");
        }

        $this->assertTrue(Ported::isPath('/my'));

        // The Command Centre flipped in Phase 5 (criterion 7). This assertion
        // read `assertFalse` with the note "the dashboard is Blade until Phase
        // 4" — the guard doing exactly its job, catching the flip rather than
        // letting a stale claim about the renderer sit in the suite.
        $this->assertTrue(Ported::isPath('/risk/dashboard'));

        $this->assertSame('', Ported::navigateAttribute('/my'));
        $this->assertSame('', Ported::navigateAttribute('/risk/dashboard'));

        // There used to be a check here against a path Blade still served, so
        // the `wire:navigate` branch of navigateAttribute() was exercised
        // rather than the assertion passing vacuously. Its subject moved with
        // every module of Phase 6 — the workflow designer, configuration
        // bundles, the integrations group — and after 6.7 there is no Blade
        // PAGE left in the product to point it at. The branch itself goes in
        // Phase 6.8 along with Livewire.
        $this->assertSame([], $this->bladePageViews(), 'A Blade page view has come back.');

        foreach (NavPresenter::allItems() as $item) {
            $expected = Ported::isRoute($item['route']);
            $this->assertSame($expected, Ported::isRoute($item['route']));
        }

        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.primary', fn ($primary) => collect($primary)
                    ->every(fn ($item) => $item['inertia'] === Ported::isRoute($item['route']))));
    }

    /**
     * Blade view files that are PAGES.
     *
     * What survives the migration is the app shell, the layout it uses, the
     * shared view components, the PDF templates, the mailable and the vendor
     * pagination views — none of which is a page a user navigates to. Anything
     * else under resources/views is a screen that should have been ported.
     *
     * Phase 6.8 narrows this further: layouts/, components/ and livewire/ go
     * when Livewire does, leaving app.blade.php, reports/pdf/**, emails/** and
     * vendor/pagination/**.
     *
     * @return list<string>
     */
    private function bladePageViews(): array
    {
        $allowed = ['app.blade.php', 'layouts/', 'components/', 'livewire/', 'reports/pdf/', 'emails/', 'vendor/'];
        $root = resource_path('views');
        $pages = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            foreach ($allowed as $prefix) {
                if ($relative === $prefix || str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $pages[] = $relative;
        }

        sort($pages);

        return $pages;
    }
}
