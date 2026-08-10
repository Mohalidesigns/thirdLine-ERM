<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment preflight: refuses to pass on a configuration that would be
 * unsafe to expose.
 *
 * Intended to run as the last step of a deploy, with a non-zero exit failing
 * the pipeline. Everything checked here has either bitten this codebase before
 * or is an invariant WP-00 introduced and needs to keep.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight {--allow-local : Do not fail merely because APP_ENV is local}';

    protected $description = 'Verify this deployment is configured safely before serving traffic';

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkDebug();
        $this->checkAppKey();
        $this->checkHttps();
        $this->checkSessionCookies();
        $this->checkNoCdnHostsInViews();
        $this->checkNoInstaller();
        $this->checkNoDevBackdoor();
        $this->checkEveryRouteIsGuarded();
        $this->checkAuditChainColumns();

        $this->newLine();
        $this->table(
            ['Check', 'Result', 'Detail'],
            array_map(fn (array $r) => [
                $r['name'],
                $r['status'] === 'pass' ? '<fg=green>PASS</>' : ($r['status'] === 'warn' ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>'),
                $r['detail'],
            ], $this->results)
        );

        $failures = collect($this->results)->where('status', 'fail')->count();
        $warnings = collect($this->results)->where('status', 'warn')->count();

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("Preflight FAILED: {$failures} blocking issue(s), {$warnings} warning(s). Do not serve traffic.");

            return self::FAILURE;
        }

        $this->components->info("Preflight passed with {$warnings} warning(s).");

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */
    /*  Checks */
    /* ------------------------------------------------------------------ */

    private function checkEnvironment(): void
    {
        $env = app()->environment();

        if ($env === 'production') {
            $this->pass('APP_ENV', 'production');

            return;
        }

        if ($this->option('allow-local')) {
            $this->warn_('APP_ENV', "is \"{$env}\", not production (allowed by --allow-local)");

            return;
        }

        $this->fail_('APP_ENV', "is \"{$env}\"; a production deployment must set APP_ENV=production");
    }

    private function checkDebug(): void
    {
        if (! config('app.debug')) {
            $this->pass('APP_DEBUG', 'disabled');

            return;
        }

        if (app()->environment('local', 'testing')) {
            $this->warn_('APP_DEBUG', 'enabled, acceptable only because this is a development environment');

            return;
        }

        $this->fail_('APP_DEBUG', 'enabled — the debug page exposes configuration and credentials');
    }

    private function checkAppKey(): void
    {
        $key = config('app.key');

        if (empty($key)) {
            $this->fail_('APP_KEY', 'not set; sessions and encrypted columns cannot be trusted');

            return;
        }

        if (in_array($key, ['base64:', 'SomeRandomString'], true)) {
            $this->fail_('APP_KEY', 'is a placeholder; run php artisan key:generate');

            return;
        }

        $this->pass('APP_KEY', 'set');
    }

    private function checkHttps(): void
    {
        $url = (string) config('app.url');

        if (str_starts_with($url, 'https://')) {
            $this->pass('HTTPS', 'APP_URL uses https');

            return;
        }

        if (app()->environment('local', 'testing')) {
            $this->warn_('HTTPS', "APP_URL is \"{$url}\" (development)");

            return;
        }

        $this->fail_('HTTPS', "APP_URL is \"{$url}\"; session cookies and MFA codes would travel in clear text");
    }

    private function checkSessionCookies(): void
    {
        $problems = [];

        if (! config('session.secure')) {
            $problems[] = 'SESSION_SECURE_COOKIE is off';
        }

        if (! config('session.http_only', true)) {
            $problems[] = 'SESSION_HTTP_ONLY is off';
        }

        if (config('session.same_site') === null) {
            $problems[] = 'SESSION_SAME_SITE is unset';
        }

        if ($problems === []) {
            $this->pass('Session cookies', 'secure, http-only, same-site set');

            return;
        }

        $detail = implode('; ', $problems);

        app()->environment('local', 'testing')
            ? $this->warn_('Session cookies', $detail.' (development)')
            : $this->fail_('Session cookies', $detail);
    }

    private function checkNoCdnHostsInViews(): void
    {
        $hosts = ['cdn.tailwindcss.com', 'cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com'];
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach ($hosts as $host) {
                if (str_contains($contents, $host)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).' → '.$host;
                }
            }
        }

        $offenders === []
            ? $this->pass('Asset residency', 'no view references an external CDN')
            : $this->fail_('Asset residency', count($offenders).' view(s) load assets from a foreign CDN: '.implode(', ', array_slice($offenders, 0, 5)));
    }

    private function checkNoInstaller(): void
    {
        $installers = array_filter([
            base_path('install.php'),
            public_path('install.php'),
        ], fn (string $path) => File::exists($path));

        $installers === []
            ? $this->pass('Web installer', 'absent')
            : $this->fail_('Web installer', 'present at '.implode(', ', $installers).'; it runs migrations and shell commands unauthenticated');
    }

    private function checkNoDevBackdoor(): void
    {
        File::exists(app_path('Http/Middleware/AutoLoginDev.php'))
            ? $this->fail_('Dev auto-login', 'AutoLoginDev middleware is present in the codebase')
            : $this->pass('Dev auto-login', 'removed');
    }

    private function checkEveryRouteIsGuarded(): void
    {
        $allowlist = [
            '/', 'up', 'login', 'logout', 'forgot-password',
            'reset-password', 'reset-password/{token}',
            'mfa/verify', 'mfa/setup', 'mfa/enable',
            'auth/sso/discover', 'auth/sso/{slug}', 'auth/sso/{slug}/callback',
            'auth/sso/{slug}/acs', 'auth/sso/{slug}/metadata',
        ];

        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), $allowlist, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $inGroup = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_contains($m, 'StartSession')
            ) || in_array('api', $route->middleware(), true);

            if (! $inGroup) {
                continue;
            }

            $guarded = collect($middleware)->contains(
                fn ($m) => is_string($m) && (
                    str_starts_with($m, 'permission:')
                    || str_starts_with($m, 'can:')
                    || $m === 'scim.auth'
                    || $m === \App\Http\Middleware\AuthenticateScim::class
                )
            );

            if (! $guarded) {
                $unguarded[] = $route->uri();
            }
        }

        $unguarded === []
            ? $this->pass('Route authorization', 'every web/api route carries a permission guard')
            : $this->fail_('Route authorization', count($unguarded).' unguarded route(s): '.implode(', ', array_slice($unguarded, 0, 5)));
    }

    private function checkAuditChainColumns(): void
    {
        if (! Schema::hasTable('risk_audit_trail')) {
            $this->fail_('Audit trail', 'risk_audit_trail table is missing; run migrations');

            return;
        }

        if (! Schema::hasColumn('risk_audit_trail', 'hash')) {
            $this->fail_('Audit trail', 'hash chain columns are missing; run migrations');

            return;
        }

        $this->pass('Audit trail', 'hash chain present — run audit:verify to check integrity');
    }

    /* ------------------------------------------------------------------ */
    /*  Result helpers */
    /* ------------------------------------------------------------------ */

    private function pass(string $name, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => 'pass', 'detail' => $detail];
    }

    private function warn_(string $name, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => 'warn', 'detail' => $detail];
    }

    private function fail_(string $name, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => 'fail', 'detail' => $detail];
    }
}
