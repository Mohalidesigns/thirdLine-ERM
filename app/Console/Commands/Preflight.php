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
        // Routes that legitimately carry no permission guard. Every entry is
        // here because something ELSE authorizes it, and that something is
        // named — an allowlist whose entries have no stated reason becomes the
        // place failures go to be forgotten.
        $allowlist = [
            // Pre-authentication by definition.
            '/', 'up', 'login', 'logout', 'forgot-password',
            'reset-password', 'reset-password/{token}',
            'mfa/verify', 'mfa/setup', 'mfa/enable',
            'auth/sso/discover', 'auth/sso/{slug}', 'auth/sso/{slug}/callback',
            'auth/sso/{slug}/acs', 'auth/sso/{slug}/metadata',

            // Livewire's two framework endpoints were here — upload-file and
            // preview-file/{filename}, neither mapping to a feature and so
            // neither taking a permission. Migration Phase 6.8 uninstalled
            // livewire/livewire, so the routes no longer exist and the entries
            // excused nothing. An allowlist entry for a route that is not
            // registered is worse than useless: it is a name that would silently
            // start excusing a real route if one ever claimed that URI.
        ];

        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), $allowlist, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // Which routes are in scope for this check.
            //
            // This used to look for StartSession in the gathered middleware,
            // on the assumption that gatherMiddleware() expands the `web`
            // group. It does not — it returns the group NAME, so the test was
            // false for every single web route and the check had only ever
            // examined the API. That is the opposite of the mistake it looks
            // like: not a check that was too strict, a check that was barely
            // running. Match the group names as they actually appear, and keep
            // the StartSession probe for routes that pin the middleware
            // directly rather than through a group.
            $inGroup = collect($middleware)->contains(
                fn ($m) => is_string($m) && (
                    $m === 'web'
                    || $m === 'api'
                    || str_contains($m, 'StartSession')
                )
            );

            if (! $inGroup) {
                continue;
            }

            $guarded = collect($middleware)->contains(
                fn ($m) => is_string($m) && $this->isAuthorizingMiddleware($m)
            );

            if (! $guarded) {
                $unguarded[] = $route->uri();
            }
        }

        $unguarded === []
            ? $this->pass('Route authorization', 'every web/api route carries a permission guard')
            : $this->fail_('Route authorization', count($unguarded).' unguarded route(s): '.implode(', ', array_slice($unguarded, 0, 5)));
    }

    /**
     * Does this middleware entry authorize the request?
     *
     * This check predated WP-07 and matched on two literal alias prefixes,
     * `permission:` and `can:`. WP-07 then added the REST API, which
     * authorizes by token scope through `scope:` and `scope.resource` — so
     * preflight reported all twelve api/v1 routes as unguarded when every one
     * of them carries a guard. Three waves of work ran against a safety check
     * that was permanently red, which is the state in which people stop
     * reading it.
     *
     * The list below is of CLASSES, and the aliases are resolved from the
     * router's own alias map at runtime. Renaming an alias in bootstrap/app.php
     * therefore cannot silently blind this check again; adding a genuinely new
     * kind of authorization middleware still requires adding it here, which is
     * the one thing that should require a deliberate edit.
     *
     * @var list<class-string>
     */
    private const AUTHORIZING_MIDDLEWARE = [
        \App\Http\Middleware\CheckPermission::class,
        \App\Http\Middleware\EnsureTokenScope::class,
        \App\Http\Middleware\EnsureResourceScope::class,
        \App\Http\Middleware\AuthenticateScim::class,
    ];

    private function isAuthorizingMiddleware(string $entry): bool
    {
        // 'permission:risk.view' -> 'permission'; a class-string is unchanged.
        $name = explode(':', $entry, 2)[0];

        if (in_array($name, self::AUTHORIZING_MIDDLEWARE, true)) {
            return true;
        }

        // Laravel's own gate middleware has no first-party class of ours.
        if ($name === 'can') {
            return true;
        }

        $class = $this->middlewareAliases()[$name] ?? null;

        return $class !== null && in_array($class, self::AUTHORIZING_MIDDLEWARE, true);
    }

    /** @return array<string, class-string> the router's alias => class map */
    private function middlewareAliases(): array
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = app('router');

        return $router->getMiddleware();
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
