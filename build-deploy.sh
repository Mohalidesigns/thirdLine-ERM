#!/bin/bash
# ─────────────────────────────────────────────────────────────────
# GRC Risk Platform - cPanel Deployment Packager
#
# Creates riskmtg.zip containing:
#   core/           -> Laravel app (app, config, routes, vendor, etc.)
#   index.php       -> Entry point (will be overwritten by installer)
#   .htaccess       -> Apache rewrite rules
#   build/          -> Compiled Vite assets
#   favicon.ico     -> Favicon
#   robots.txt      -> Robots file
#   install.php     -> Web installer
#
# Usage: bash build-deploy.sh
# ─────────────────────────────────────────────────────────────────

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

BUILD_DIR="$SCRIPT_DIR/.deploy-build"
OUTPUT="$SCRIPT_DIR/riskmtg.zip"

echo "=== GRC Risk Platform - Deployment Packager ==="
echo ""

# Clean previous build
rm -rf "$BUILD_DIR" "$OUTPUT"
mkdir -p "$BUILD_DIR/core"

echo "[1/6] Ensuring dependencies are installed ..."
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -3

echo "[2/6] Building frontend assets ..."
npm run build 2>&1 | tail -3

echo "[3/6] Copying Laravel core files ..."
# Core Laravel directories
for dir in app bootstrap config database resources routes storage vendor; do
    cp -r "$dir" "$BUILD_DIR/core/"
done

# Core Laravel files
for file in artisan composer.json composer.lock; do
    cp "$file" "$BUILD_DIR/core/"
done

# Ensure storage structure exists
mkdir -p "$BUILD_DIR/core/storage/app/public"
mkdir -p "$BUILD_DIR/core/storage/app/private"
mkdir -p "$BUILD_DIR/core/storage/framework/cache/data"
mkdir -p "$BUILD_DIR/core/storage/framework/sessions"
mkdir -p "$BUILD_DIR/core/storage/framework/testing"
mkdir -p "$BUILD_DIR/core/storage/framework/views"
mkdir -p "$BUILD_DIR/core/storage/logs"
mkdir -p "$BUILD_DIR/core/bootstrap/cache"

# Add .gitignore placeholders so empty dirs are kept
for d in "$BUILD_DIR/core/storage/app/public" \
         "$BUILD_DIR/core/storage/app/private" \
         "$BUILD_DIR/core/storage/framework/cache/data" \
         "$BUILD_DIR/core/storage/framework/sessions" \
         "$BUILD_DIR/core/storage/framework/views" \
         "$BUILD_DIR/core/storage/logs" \
         "$BUILD_DIR/core/bootstrap/cache"; do
    touch "$d/.gitkeep"
done

echo "[4/6] Copying public assets ..."
# Public directory contents go to root of deploy
cp public/.htaccess "$BUILD_DIR/"
cp public/index.php "$BUILD_DIR/"
[ -f public/favicon.ico ] && cp public/favicon.ico "$BUILD_DIR/"
[ -f public/robots.txt ] && cp public/robots.txt "$BUILD_DIR/"

# Compiled assets
if [ -d public/build ]; then
    cp -r public/build "$BUILD_DIR/"
fi

echo "[5/6] Including installer ..."
cp install.php "$BUILD_DIR/"

echo "[6/6] Creating riskmtg.zip ..."
cd "$BUILD_DIR"
zip -r "$OUTPUT" . -x "*.DS_Store" "*__MACOSX*" "*.git*" 2>&1 | tail -1

# Cleanup
cd "$SCRIPT_DIR"
rm -rf "$BUILD_DIR"

SIZE=$(du -h "$OUTPUT" | cut -f1)
echo ""
echo "=== Done! ==="
echo "Output: riskmtg.zip ($SIZE)"
echo ""
echo "Deployment steps:"
echo "  1. Upload riskmtg.zip to public_html/riskmtg/"
echo "  2. Upload install.php to public_html/riskmtg/"
echo "  3. Visit https://yourdomain.com/riskmtg/install.php"
echo "  4. Click 'Install Now' and wait for completion"
echo "  5. Click 'Clean Up Install Files' to remove installer"
echo "  6. Login with admin@risk.test / password"
