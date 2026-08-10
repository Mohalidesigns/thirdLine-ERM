<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform is sold on-premise, so a page load must not tell a third party
 * that someone is using it. Nothing may be fetched from a foreign host.
 */
class AssetResidencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const FORBIDDEN_HOSTS = [
        'cdn.tailwindcss.com',
        'cdn.jsdelivr.net',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'unpkg.com',
        'cdnjs.cloudflare.com',
        'ajax.googleapis.com',
    ];

    #[Test]
    public function no_blade_view_references_an_external_cdn(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach (self::FORBIDDEN_HOSTS as $host) {
                if (str_contains($contents, $host)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).' → '.$host;
                }
            }
        }

        $this->assertSame([], $offenders, "Views loading assets from a foreign CDN:\n  ".implode("\n  ", $offenders));
    }

    #[Test]
    public function no_bundled_asset_references_an_external_cdn(): void
    {
        // The Blade source can be clean while a stylesheet still @imports a
        // remote font, so check what the build actually produces.
        $buildPath = public_path('build');

        if (! File::isDirectory($buildPath)) {
            $this->markTestSkipped('No compiled assets present; run `npm run build`.');
        }

        $offenders = [];

        foreach (File::allFiles($buildPath) as $file) {
            if (! in_array($file->getExtension(), ['css', 'js'], true)) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach (self::FORBIDDEN_HOSTS as $host) {
                if (str_contains($contents, $host)) {
                    $offenders[] = $file->getFilename().' → '.$host;
                }
            }
        }

        $this->assertSame([], $offenders, "Compiled assets referencing a foreign CDN:\n  ".implode("\n  ", $offenders));
    }

    #[Test]
    public function the_rendered_login_page_loads_nothing_from_a_foreign_host(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        foreach (self::FORBIDDEN_HOSTS as $host) {
            $this->assertStringNotContainsString($host, $html, "The login page still loads assets from {$host}");
        }

        $this->assertStringContainsString('/build/assets/', $html, 'The login page is not loading the Vite bundle');
    }

    #[Test]
    public function an_authenticated_page_loads_nothing_from_a_foreign_host(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $org = Organization::create([
            'name' => 'Residency Bank PLC',
            'short_name' => 'RESID',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Dashboard User',
            'email' => 'residency@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $user->assignRole('super-admin');

        $html = $this->actingAs($user)->get('/risk/register')->assertOk()->getContent();

        foreach (self::FORBIDDEN_HOSTS as $host) {
            $this->assertStringNotContainsString($host, $html, "The risk register still loads assets from {$host}");
        }

        $this->assertStringContainsString('/build/assets/', $html, 'The risk register is not loading the Vite bundle');
    }

    #[Test]
    public function the_self_hosted_fonts_are_present(): void
    {
        $this->assertTrue(File::exists(public_path('fonts/Inter-1.woff2')), 'Inter is not self-hosted');
        $this->assertTrue(
            File::exists(public_path('fonts/MaterialSymbolsOutlined-1.woff2')),
            'Material Symbols is not self-hosted'
        );
    }

    #[Test]
    public function organization_branding_overrides_the_palette(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $org = Organization::create([
            'name' => 'Branded Bank PLC',
            'short_name' => 'BRAND',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
            'settings' => ['branding' => ['primary' => '#0B3D2E', 'accent' => '#F2A900']],
        ]);

        $user = User::create([
            'name' => 'Branded User',
            'email' => 'branded@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $user->assignRole('super-admin');

        $html = $this->actingAs($user)->get('/risk/register')->assertOk()->getContent();

        $this->assertStringContainsString('--color-primary: #0B3D2E', $html);
        $this->assertStringContainsString('--color-accent: #F2A900', $html);
    }

    #[Test]
    public function branding_values_that_are_not_colours_are_not_emitted(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // organizations.settings is tenant-controlled and this value lands
        // inside a <style> block, so anything that is not a hex colour must be
        // dropped rather than printed.
        $org = Organization::create([
            'name' => 'Hostile Bank PLC',
            'short_name' => 'HOST',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
            'settings' => ['branding' => ['primary' => 'red; } body { display: none } .x {']],
        ]);

        $user = User::create([
            'name' => 'Hostile User',
            'email' => 'hostile@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $user->assignRole('super-admin');

        $html = $this->actingAs($user)->get('/risk/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('body { display: none }', $html);
        $this->assertStringNotContainsString('--color-primary: red', $html);
    }
}
