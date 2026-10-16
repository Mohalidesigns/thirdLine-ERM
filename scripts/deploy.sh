#!/usr/bin/env bash
# Server-side deploy script — invoked by the GitHub Actions runner over SSH
# as the unprivileged `deploy` user. Pulls latest main, rebuilds, migrates,
# and restarts services (restarts via scoped passwordless sudo).
set -euo pipefail

APP_DIR=/var/www/thirdLine-ERM

# Re-apply runtime-writable permissions on storage/ and bootstrap/cache, run
# from the current directory.
#
# This is housekeeping, and it must never stop a deploy. Until 2026-10-05 it
# was two bare `find … -exec chmod` lines under `set -e`. The day after RCSA v2
# was switched on, php-fpm had created storage/app/private/rcsa as www-data
# with Laravel's private-directory default of 0700; `deploy` could not list it,
# find exited 1, and the deploy died here — before preflight, before the
# restart — and would have died the same way on every deploy after it.
#
# So, deliberately:
#   - a directory this user cannot list AND enter is pruned, not descended
#     into, and named in a warning. The test is `test -r && test -x` through
#     -exec rather than GNU find's -readable: it is POSIX, so the one code path
#     tests/Feature/DeployScriptRuntimePermissionsTest exercises on a developer
#     Mac is the same one that runs on the Ubuntu host, and it also catches a
#     directory that is readable but not searchable, which -readable does not;
#   - only entries this user owns are chmod'ed. Anything php-fpm or the queue
#     worker created belongs to www-data, and chmod on it would fail anyway;
#   - storage/app/private — the `local` disk root, holding loss-event, issue,
#     control-test, TPRM and BCMS evidence and the RCSA import/export
#     workbooks — is 2770/0660, never world-readable. Everything else keeps
#     2775/0664. config/filesystems.php gives the app the same split;
#   - find's exit status is captured, not trusted to `set -e`: a cache or
#     session file deleted by php-fpm between find listing it and chmod
#     reaching it is a normal race on a live host, not a reason to abort.
#
# Returns 0 always. What it could not reach is printed for an operator to fix
# as root — see docs/DEPLOYMENT.md, "When the permissions step warns".
ensure_runtime_permissions() {
    local me errlog unreachable status=0
    me="$(id -un)"
    errlog="$(mktemp 2>/dev/null)" || errlog=/dev/null

    unreachable="$(
        find storage bootstrap/cache \
            -type d \( ! -exec test -r {} \; -o ! -exec test -x {} \; \) -prune -print \
            -o -user "$me" \( -path storage/app/private -o -path 'storage/app/private/*' \) \
                \( -type d -exec chmod 2770 {} + -o -type f -exec chmod 0660 {} + \) \
            -o -user "$me" -type d -exec chmod 2775 {} + \
            -o -user "$me" -type f -exec chmod 0664 {} + \
            2>"$errlog"
    )" || status=$?

    if [[ -n "$unreachable" ]]; then
        echo "!!  WARNING: '$me' cannot list or enter these directories under storage/ or"
        echo "!!  bootstrap/cache; they and everything below them were skipped:"
        printf '%s\n' "$unreachable" | sed 's/^/!!    /'
        echo "!!  The app will keep working, but fix them as root before they hide more:"
        echo "!!  see docs/DEPLOYMENT.md, 'When the permissions step warns'."
    fi

    if [[ "$status" -ne 0 ]]; then
        echo "!!  WARNING: permissions step finished with status $status; continuing. find/chmod reported:"
        if [[ "$errlog" != /dev/null ]]; then
            sed 's/^/!!    /' "$errlog"
        fi
    fi

    if [[ "$errlog" != /dev/null ]]; then
        rm -f "$errlog"
    fi

    return 0
}

# `deploy.sh --permissions-only DIR` runs ONLY the step above, against DIR,
# under this script's own `set -euo pipefail`, and exits. It exists so a test
# can execute the real function rather than a copy of it. It touches nothing
# outside DIR and runs no other step.
if [[ "${1:-}" == "--permissions-only" ]]; then
    cd "${2:?--permissions-only needs a directory}"
    ensure_runtime_permissions
    exit 0
fi

cd "$APP_DIR"

echo "==> Fetching latest main"
git config --global --add safe.directory "$APP_DIR" || true
git fetch --all --prune

# ADR 0029. The site goes down BEFORE `git reset` and `composer install`, not
# before `migrate`. `composer install` runs Laravel's postAutoloadDump, which
# deletes bootstrap/cache/config.php, so from that moment every new process
# reads config/ from disk, not from the previous release's cache. That matters
# whenever a release changes how a database session behaves: ADR 0029's UTC
# pin is the case that needs it, and once the pin lands a writer between there
# and the end of `migrate` would put true-UTC values into tables its
# conversion has not reached yet, which the conversion would then shift again.
# The same window keeps new code off the old schema for every deploy.
#
# `down` runs here on the PREVIOUS release's code and cached config. php-fpm
# answers 503 before the framework boots, and schedule:run skips every event.
# The build time is therefore downtime; that cost is accepted. A failed fetch
# above costs none.
#
# If anything below fails, the site STAYS down: that is deliberate, and the
# ERR trap says so. Do not run `php artisan up` by hand — see the preflight
# failure message, and docs/DEPLOYMENT.md.
echo "==> Maintenance mode on"
php artisan down --retry=60
trap 'echo "!!  DEPLOY FAILED IN MAINTENANCE MODE: the site is serving 503. See docs/DEPLOYMENT.md."' ERR

# The drain. SIGTERM lets the job in flight finish and systemctl blocks until
# the worker has exited. The replacement boots on the previous release and
# parks, because a worker does not run jobs while the application is down
# (unless its unit passes --force; docs/DEPLOYMENT.md says to check). It does
# not run a job until the final restart below replaces it.
echo "==> Draining the queue worker"
sudo /usr/bin/systemctl restart risk-queue

echo "==> Resetting to origin/main"
git reset --hard origin/main

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Frontend build"
npm ci --no-audit --no-fund
npm run build

# A schedule:run that cron started just before `down` is still running its
# foreground commands on the old configuration. Let it finish before anything
# is converted. The build has usually outlasted it already. Two consecutive
# empty polls are required, so a run cron starts between one poll and the next
# is seen. The bound is 5 minutes, so drain + build + wait + conversion fit in
# the workflow's 40m command_timeout. On timeout `false` fires the ERR trap
# (a bare `exit 1` would not), with the site still down, rather than migrating
# under a live writer. Needs `deploy` to see other users' processes (no hidepid).
echo "==> Waiting for any in-flight schedule:run"
quiet=0
for _ in $(seq 1 60); do
    if pgrep -f 'artisan schedule:run' >/dev/null; then
        quiet=0
    else
        quiet=$((quiet + 1))
        [[ "$quiet" -ge 2 ]] && break
    fi
    sleep 5
done
if [[ "$quiet" -lt 2 ]]; then
    echo "!!  schedule:run still running after 5 minutes; not migrating under a live writer."
    echo "!!  The site is in MAINTENANCE MODE and serving 503."
    false
fi

echo "==> Migrations"
php artisan migrate --force

# The permission catalogue is additive: SeedsPermissions::syncCatalog uses
# findOrCreate and givePermissionTo and never revokes, so re-running it on every
# deploy adds the permissions a release introduced to tenants that already exist
# — which a fresh-install seeder otherwise never reaches. Note that it also
# re-grants a catalogue permission an administrator had removed from a built-in
# role, and recreates a built-in role that was deleted.
echo "==> Sync permission catalog"
php artisan db:seed --class=RolesAndPermissionsSeeder --force

echo "==> Cache config/routes/views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Files are owned deploy:www-data with setgid dirs, so www-data inherits
# group ownership automatically — only runtime-writable trees need g+w.
# Never fatal: see ensure_runtime_permissions above.
echo "==> Ensure runtime-writable permissions"
ensure_runtime_permissions

# Deliberately AFTER migrate and the config/route/view caches, so preflight
# inspects the configuration and schema this deploy actually produced rather
# than the previous one — several checks read the database, and the module gate
# reads config('features.*') as cached.
#
# And deliberately BEFORE the restart: the last point where this script can
# decline to put new workers into service. The site is in maintenance mode
# throughout, so a failure here leaves it serving 503, the worker parked on the
# previous release, and nothing writing. Migrations have already run: the
# schema moved and the services did not.
echo "==> Preflight"
if ! php artisan app:preflight; then
    echo
    echo "!!  PREFLIGHT FAILED — services were NOT restarted."
    echo "!!  The application is in MAINTENANCE MODE (serving 503), the queue worker is"
    echo "!!  parked and nothing is writing. Fix what preflight reported, then re-run the"
    echo "!!  deploy. Do NOT run 'php artisan up' by hand without first running"
    echo "!!  'sudo systemctl restart risk-queue': the parked worker is on the previous"
    echo "!!  release's code and configuration and must not resume against this one."
    echo
    exit 1
fi

echo "==> Restart services"
sudo /usr/bin/systemctl restart php8.4-fpm
sudo /usr/bin/systemctl restart risk-queue

echo "==> Maintenance mode off"
php artisan up

echo "==> Deploy complete: $(git rev-parse --short HEAD)"
