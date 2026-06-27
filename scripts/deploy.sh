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

echo "==> Restart services"
sudo /usr/bin/systemctl restart php8.4-fpm
sudo /usr/bin/systemctl restart risk-queue

echo "==> Deploy complete: $(git rev-parse --short HEAD)"
