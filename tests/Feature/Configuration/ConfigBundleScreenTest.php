<?php

namespace Tests\Feature\Configuration;

use App\Models\ConfigBundle;
use App\Models\Organization;
use App\Services\Configuration\ConfigurationExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The bundle screen (migration Phase 6.6).
 *
 * `ConfigBundleTest` covers what a bundle IS — what it captures, how it
 * checksums, what a diff reports, what a rollback restores. This file covers
 * the screen: that the dry run is a page rather than a flash, that applying
 * demands a confirmation, and that neither the picker nor the validator will
 * take another institution's bundle.
 */
class ConfigBundleScreenTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function bundle(): ConfigBundle
    {
        return app(ConfigurationExporter::class)->export(
            code: 'baseline',
            name: 'Baseline',
            description: 'Everything as it stands.',
        );
    }

    #[Test]
    public function the_screen_lists_bundles_and_their_history(): void
    {
        $this->bundle();

        $this->get(route('admin.configuration'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConfigBundles/Index')
                ->has('bundles', 1)
                ->where('bundles.0.code', 'baseline')
                ->has('bundles.0.total_rows')
                ->has('applications')
            );
    }

    #[Test]
    public function the_dry_run_renders_the_diff_rather_than_flashing_it(): void
    {
        // The Blade screen put the whole diff through the session and read it
        // back on the next request — a configuration-sized document in the
        // session store for the sake of one redirect.
        $bundle = $this->bundle();

        $this->post(route('admin.configuration.diff'), ['bundle_id' => $bundle->id])
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConfigBundles/Diff')
                ->where('bundle.code', 'baseline')
                ->where('uploaded', false)
                // Exporting and immediately diffing compares a configuration
                // against itself, so there is nothing to do.
                ->where('diff.is_empty', true)
            );
    }

    #[Test]
    public function a_dry_run_writes_no_configuration(): void
    {
        $bundle = $this->bundle();
        $before = \App\Models\ObjectType::count();

        $this->post(route('admin.configuration.diff'), ['bundle_id' => $bundle->id])->assertOk();

        $this->assertSame($before, \App\Models\ObjectType::count());
    }

    #[Test]
    public function applying_without_the_confirmation_is_refused(): void
    {
        $bundle = $this->bundle();

        $this->post(route('admin.configuration.apply', $bundle->id), ['force' => true])
            ->assertSessionHasErrors('confirm');
    }

    #[Test]
    public function another_institutions_bundle_is_not_offered_and_not_accepted(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB8',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $theirs = ConfigBundle::withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'code' => 'theirs',
            'name' => 'Theirs',
            'version' => 1,
            'payload' => ['sections' => []],
            'checksum' => 'x',
            'exported_at' => now(),
        ]);

        // A bundle is a complete statement of another organisation's
        // configuration, so the id is refused by the validator rather than
        // reaching the importer.
        $this->post(route('admin.configuration.diff'), ['bundle_id' => $theirs->id])
            ->assertSessionHasErrors('bundle_id');

        $this->post(route('admin.configuration.apply', $theirs->id), ['confirm' => '1'])
            ->assertNotFound();

        $this->get(route('admin.configuration.download', $theirs->id))->assertNotFound();
    }

    #[Test]
    public function a_dry_run_with_neither_a_bundle_nor_a_file_says_so(): void
    {
        $this->post(route('admin.configuration.diff'), [])
            ->assertSessionHasErrors('bundle');
    }

    #[Test]
    public function the_screen_needs_the_configuration_permission(): void
    {
        $analyst = \App\Models\User::create([
            'name' => 'Analyst',
            'email' => 'analyst-config@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $analyst->assignRole('risk-analyst');

        $this->actingAs($analyst)->get(route('admin.configuration'))->assertForbidden();
    }
}
