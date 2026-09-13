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
        // A NET, NOT THE FIX. `max_execution_time` is process global and the CLI
        // default is 0 (unlimited), so ONE `set_time_limit($n)` anywhere in app
        // code hands the REST OF THE SUITE a shared $n-second countdown restarted
        // from that moment — and the suite then dies of `Maximum execution time
        // exceeded` on tests unrelated to whatever set it. That happened:
        // `Risk\AiToolsController` called `set_time_limit(120)` in seven actions,
        // and `LlmGatewayNumCtxTest` posts to one of them and waits ~33s on a real
        // model. The controller is fixed (it now only raises a limit that already
        // exists, so it is inert under CLI); this line bounds the blast radius of
        // the NEXT one to a single test. It is silent by nature, so it must not be
        // what keeps the class out of the codebase — a guard test that fails on an
        // unguarded process-global mutation in app/ is what does that.
        @set_time_limit(0);

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
