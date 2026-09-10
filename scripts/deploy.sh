#!/usr/bin/env bash
# Server-side deploy script — invoked by the GitHub Actions runner over SSH
# as the unprivileged `deploy` user. Pulls latest main, rebuilds, migrates,
# and restarts services (restarts via scoped passwordless sudo).
set -euo pipefail

APP_DIR=/var/www/thirdLine-ERM
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

echo "==> Cache config/routes/views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Files are owned deploy:www-data with setgid dirs, so www-data inherits
# group ownership automatically — only runtime-writable trees need g+w.
echo "==> Ensure runtime-writable permissions"
find storage bootstrap/cache -type d -exec chmod 2775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

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
