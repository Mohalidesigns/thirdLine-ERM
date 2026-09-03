<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The licensing services read/write the REAL storage/licensing directory —
     * there is no test-specific path. LicenseActivationTest stores, corrupts
     * and deletes these files, which would wipe the developer machine's active
     * licence on every `php artisan test`. Snapshot the files before each test
     * and restore them after, so the suite is hermetic with respect to the
     * machine's licence. (From ThirdLine's TestCase.)
     *
     * @var array<string, string|null>
     */
    private array $licenseFileSnapshot = [];

    /** Whether @vite is stubbed out for this test class. */
    protected bool $stubVite = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia pages render through resources/views/app.blade.php, whose
        // @vite directive needs a built manifest. The suite must not depend on
        // `npm run build` having run, so the directive is stubbed out — except
        // for a test that is about the bundle itself (AssetResidencyTest),
        // which sets $stubVite = false and runs against the real build.
        if ($this->stubVite) {
            $this->withoutVite();
        }

        foreach (['license.enc', 'license.sig'] as $file) {
            $path = storage_path('licensing/'.$file);
            $this->licenseFileSnapshot[$path] = file_exists($path) ? file_get_contents($path) : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->licenseFileSnapshot as $path => $contents) {
            if ($contents === null) {
                @unlink($path);
            } else {
                @mkdir(dirname($path), 0700, true);
                file_put_contents($path, $contents);
            }
        }

        parent::tearDown();
    }
}
