#!/usr/bin/env bash
# Server-side deploy script — invoked by the GitHub Actions runner over SSH.
# Pulls latest main, rebuilds, migrates, and restarts services.
set -euo pipefail

APP_DIR=/var/www/thirdLine-ERM
cd "$APP_DIR"

echo "==> Pulling latest main"
git config --global --add safe.directory "$APP_DIR" || true
git fetch --all --prune
git reset --hard origin/main

echo "==> PHP dependencies"
export COMPOSER_ALLOW_SUPERUSER=1
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

echo "==> Permissions"
chown -R www-data:www-data "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "==> Restart services"
systemctl restart php8.4-fpm
systemctl restart risk-queue

echo "==> Deploy complete: $(git rev-parse --short HEAD)"
