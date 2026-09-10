<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
    /**
     * The engine the test suite and CI are pinned to.
     *
     * Kept honest by PreflightDatabaseEngineTest, which reads the service image
     * out of .github/workflows/ci.yml and fails if the two drift apart — a
     * constant whose only guarantee is a comment saying "keep this in step" is
     * the same defect one level up from the one this check exists to catch.
     */
    public const EXPECTED_DB_ENGINE = 'MariaDB';

    /**
     * The sentence in docs/bcms/phase-7-handoff.md that means "not certified".
     *
     * Deleting it from that document is how Phase 7 gets certified, and it is
     * meant to be a deliberate act by whoever ran the gates — not something
     * this file can decide.
     */
    private const UNCERTIFIED_MARKER = 'THE QA GATE AND THE REVIEW GATE HAVE NOT BEEN RUN';

    /** Where the BCMS Phase 7 certification state is recorded. */
    private const BCMS_HANDOFF = 'docs/bcms/phase-7-handoff.md';

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
        $this->checkDatabaseEngine();
        $this->checkEnabledModules();
        $this->checkUncertifiedModulesAreOff();

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

            // A SIGNED capability URL rather than an unauthenticated one. The
            // BCMS calendar feed is fetched by Outlook and Google, which send
            // no cookie and no bearer token, so a `permission:` middleware
            // could never pass; `ValidateSignature` is what authorizes it, per
            // user and tamper-evident, and what it exposes is one user's own
            // calendar. Phase4ScreensTest covers the tampered-URL,
            // disabled-account and cross-tenant cases.
            'bcms/calendar/{user}/calendar.ics',

            // BCMS Phase 6 — the three cascade acknowledgement routes, and the
            // second member of the signed-capability category the calendar feed
            // opened. The credential is a 16-character HMAC of the test-node
            // id, compared with `hash_equals` and unguessable without the app
            // key; the controller resolves the tenant from the node before it
            // reads anything, because `OrganizationScope` is inert untenanted.
            //
            // WHY NOT `signed`. The URL travels in an SMS. A Laravel signed URL
            // is ~120 characters of query string, which pushes a 160-character
            // message into two segments and doubles the cost of every cascade —
            // and the security property is identical, an unguessable
            // capability in the URL. The short form is a cost decision, not a
            // weaker one.
            //
            // The inbound webhook is the one that cannot carry a per-user
            // credential at all: a gateway posts to it. It is throttled, it
            // matches a token inside the body, and it answers an unmatched
            // reply with `matched: false` rather than an error a gateway would
            // retry. PROVIDER SIGNATURE VERIFICATION IS PHASE 7'S, with the
            // real adapters that know each provider's scheme.
            'bcms/cascade/{token}',
            'bcms/cascade-inbound',
            // BCMS Phase 7 — the two EMNS provider callbacks, and the same
            // category again. A gateway posting a delivery receipt has no
            // session and never will; a person replying "SAFE" from a feature
            // phone has none either. What stands in for a login: a per-provider
            // shared secret compared with `hash_equals` on the status route, a
            // rate limit on both, and the rule that the body may never name a
            // recipient — a reply carries a token this system minted and a
            // receipt carries a message id this system stored. A payload that
            // could say "recipient 4192 is safe" is a payload that can mark a
            // whole branch safe from the public internet.
            'bcms/alert-reply',
            'bcms/provider-status/{provider}',

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
        \ThirdLine\Platform\Http\Middleware\CheckPermission::class,
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

    /**
     * Report the database engine this environment is ACTUALLY running.
     *
     * Nothing in a Laravel configuration can tell you this, and that has cost
     * this project real time more than once. `DB_CONNECTION=mysql` names the
     * PDO driver, and MariaDB and MySQL share it — the value is identical and
     * correct for both. Hosting panels are no better: XAMPP's control panel on
     * a developer machine here says "Starting MySQL Database..." over a server
     * that reports `10.4.28-MariaDB`, and the shared-hosting panel this product
     * is deployed behind says "MySQL Database" too.
     *
     * Three labels, all saying MySQL, none of them evidence. The only thing
     * that answers the question is asking the server, so this asks it and
     * prints the answer on every environment it runs in — including the ones
     * nobody can easily log into.
     *
     * It does NOT fail on a particular engine. Which engine is right is a
     * decision for whoever owns the estate, and a preflight check is the wrong
     * place to litigate it. It fails only when the engine cannot be determined
     * at all, and warns when what is running disagrees with what the test suite
     * and CI are pinned to — because that gap is the one that ships defects a
     * green suite promised were not there.
     */
    private function checkDatabaseEngine(): void
    {
        $driver = DB::connection()->getDriverName();

        // Ask the driver its own question. `version()` is not universal —
        // SQLite has `sqlite_version()` and no `version()` at all, so the first
        // cut of this check FAILED preflight on every SQLite deployment while
        // claiming to fail "only when the engine cannot be determined". SQLite
        // is perfectly determinable; the query was just wrong for it.
        [$sql, $engine] = match ($driver) {
            'mysql', 'mariadb' => ['select version() as v', null],   // decided below
            'sqlite' => ['select sqlite_version() as v', 'SQLite'],
            'pgsql' => ['select version() as v', 'PostgreSQL'],
            'sqlsrv' => ['select @@version as v', 'SQL Server'],
            default => [null, null],
        };

        if ($sql === null) {
            $this->fail_('Database engine', "unrecognised driver [{$driver}]; cannot determine the engine");

            return;
        }

        try {
            $version = trim((string) DB::selectOne($sql)->v);
        } catch (\Throwable $e) {
            $this->fail_('Database engine', "could not read the version over [{$driver}]: ".$e->getMessage());

            return;
        }

        // MariaDB and MySQL share the `mysql` driver, so the driver name cannot
        // separate them and neither can DB_CONNECTION. Only the server's own
        // version string can: MariaDB stamps itself into it, MySQL does not.
        $engine ??= str_contains(strtolower($version), 'mariadb') ? 'MariaDB' : 'MySQL';

        // version() reports e.g. "10.4.28-MariaDB"; the suffix names the engine
        // we have just named, so it is not repeated in the number.
        $number = trim((string) preg_replace('/-mariadb.*$/i', '', $version));
        $detail = "{$engine} {$number} (driver: {$driver})";

        if ($engine !== self::EXPECTED_DB_ENGINE) {
            $this->warn_('Database engine', $detail.' — the suite and CI are pinned to '.self::EXPECTED_DB_ENGINE.
                '. One of the two is wrong, and a green suite is not evidence about this server until they agree.');

            return;
        }

        $this->pass('Database engine', $detail);
    }

    /**
     * Say which modules this installation actually serves.
     *
     * Nothing else tells an operator. Every module flag in `config/features.php`
     * defaults to FALSE, so an installation that never sets `FEATURE_TPRM`
     * serves 404 on every TPRM route — the module looks absent rather than
     * switched off, and the first person to notice is the customer. The same
     * argument that justified reporting the database engine applies here
     * verbatim: the configuration cannot be inferred from the outside, so the
     * deployment should state it.
     */
    private function checkEnabledModules(): void
    {
        $flags = (array) config('features', []);
        $on = array_keys(array_filter($flags, fn ($v) => (bool) $v));
        $off = array_keys(array_filter($flags, fn ($v) => ! (bool) $v));

        sort($on);
        sort($off);

        if ($on === []) {
            $this->warn_('Modules enabled', 'NONE — every feature flag is off, so this installation serves no gated module at all');

            return;
        }

        $this->pass('Modules enabled', implode(', ', $on).($off === [] ? '' : '  ·  off: '.implode(', ', $off)));
    }

    /**
     * Refuse to serve a module that has not passed its gates.
     *
     * BCMS Phase 7 — nine channel adapters and two unauthenticated provider
     * callbacks — was merged without qa-engineer or code-reviewer having run
     * against it. That is defensible only because the module ships dark: with
     * `features.bcms` false, all 167 of its routes 404 and every `bcms:`
     * command returns immediately.
     *
     * "Ships dark" is a property worth exactly as much as whatever enforces it.
     * A note in a handoff document is a convention, and conventions last until
     * the first person who needs a demo environment. This is the enforcement:
     * turn the flag on before the gates have run and the deploy fails.
     *
     * The certification state is read from the handoff document itself rather
     * than from a constant here, so certifying is a single deliberate edit in
     * the place that records the decision — and cannot be done by accident from
     * this file.
     */
    private function checkUncertifiedModulesAreOff(): void
    {
        // Path is overridable so the missing-file branch below can be tested
        // without moving a real document around on disk.
        $handoff = base_path((string) config('preflight.bcms_handoff', self::BCMS_HANDOFF));

        if (! config('features.bcms')) {
            $this->pass('Uncertified modules', 'BCMS is off, as it must be until Phase 7 passes both gates');

            return;
        }

        if (! File::exists($handoff)) {
            // FAIL, not warn. A gate that cannot confirm certification must
            // refuse, because the alternative is that deleting, moving or
            // renaming one markdown file silently downgrades a release gate
            // into a notice nobody reads — and handle() only fails a deploy on
            // failures, never on warnings. That is not hypothetical: the
            // .gitignore commit alongside this one records that the
            // /plans/ → docs/history move is still outstanding, so documents
            // in this repository do move.
            //
            // Failing open in the check whose whole purpose is to stop
            // uncertified code being served would be the same defect this
            // release gate exists to prevent, one level up. Found by
            // qa-engineer at gate 1 cycle 4.
            $this->fail_('Uncertified modules', 'BCMS is ON and '.self::BCMS_HANDOFF.' is missing, so its certification '.
                'cannot be confirmed. Restore the document or turn FEATURE_BCMS off; do not serve an unverifiable module.');

            return;
        }

        if (str_contains(File::get($handoff), self::UNCERTIFIED_MARKER)) {
            $this->fail_('Uncertified modules', 'BCMS IS ON but docs/bcms/phase-7-handoff.md still says the gates have not been run. '.
                'Phase 7 ships two unauthenticated provider callbacks. Run qa-engineer and code-reviewer against it, or turn FEATURE_BCMS off.');

            return;
        }

        $this->pass('Uncertified modules', 'BCMS is on and its Phase 7 handoff no longer reports ungated code');
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
