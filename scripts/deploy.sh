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
#   - anything this user does not own is pruned, subtree and all, and listed
#     as skipped (not as a warning). php-fpm and the queue worker run as
#     www-data and create directories of their own under storage; only a
#     file's owner may chmod it, and a www-data path is already writable by
#     www-data, so skipping it loses nothing. The 2026-10-16 deploy of 6ae4611
#     stopped on www-data-owned storage/app/private/rcsa (PR #20). Ownership
#     drift is worth seeing in the deploy log, so each skipped path is named;
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
    local DEPLOY_USER errlog listed status=0
    DEPLOY_USER="$(id -un)"
    errlog="$(mktemp 2>/dev/null)" || errlog=/dev/null

    # One walk. Not-mine is pruned first, so nothing below it is visited; then a
    # directory this user owns but cannot list or enter; then the private disk;
    # then everything else. The two printf tags are split apart below. A path
    # with a newline in it would split its tagged line and print wrongly; that
    # touches only the listing, never which paths are chmod'ed, and nothing
    # under storage/ is named that way.
    listed="$(
        find storage bootstrap/cache \
            ! -user "$DEPLOY_USER" -prune -exec printf 'notmine\t%s\n' {} \; \
            -o -type d \( ! -exec test -r {} \; -o ! -exec test -x {} \; \) -prune -exec printf 'unreachable\t%s\n' {} \; \
            -o \( -path storage/app/private -o -path 'storage/app/private/*' \) \
                \( -type d -exec chmod 2770 {} + -o -type f -exec chmod 0660 {} + \) \
            -o -type d -exec chmod 2775 {} + \
            -o -type f -exec chmod 0664 {} + \
            2>"$errlog"
    )" || status=$?

    if grep -q '^notmine' <<<"$listed"; then
        grep '^notmine' <<<"$listed" | cut -f2- | sed 's/^/    skipped, not owned by '"$DEPLOY_USER"': /'
    fi

    if grep -q '^unreachable' <<<"$listed"; then
        echo "!!  WARNING: '$DEPLOY_USER' cannot list or enter these directories under storage/ or"
        echo "!!  bootstrap/cache; they and everything below them were skipped:"
        grep '^unreachable' <<<"$listed" | cut -f2- | sed 's/^/!!    /'
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

echo "==> Pulling latest main"
git config --global --add safe.directory "$APP_DIR" || true
git fetch --all --prune
git reset --hard origin/main

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Frontend build"
npm ci --no-audit --no-fund
npm run build

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
# And deliberately BEFORE the restart. It is the last point where this script
# can decline to put new workers into service. Not a perfect boundary: the code
# is already on disk from the git reset above, and php-fpm picks up changed
# files on its own unless opcache.validate_timestamps=0 — but the queue worker
# keeps running the old code until it is restarted, and a failed deploy that
# stops here is far easier to reason about than one that completed.
#
# Migrations have already run by this point. That is unavoidable without a
# more elaborate scheme and is worth knowing: a preflight failure means the
# schema moved and the services did not.
echo "==> Preflight"
if ! php artisan app:preflight; then
    echo
    echo "!!  PREFLIGHT FAILED — services were NOT restarted."
    echo "!!  New code is on disk and migrations have run; the queue worker is still"
    echo "!!  on the previous release. Fix what preflight reported, then re-run the"
    echo "!!  deploy. Do not restart the services by hand to 'get it up'."
    echo
    exit 1
fi

echo "==> Restart services"
sudo /usr/bin/systemctl restart php8.4-fpm
sudo /usr/bin/systemctl restart risk-queue

echo "==> Deploy complete: $(git rev-parse --short HEAD)"
