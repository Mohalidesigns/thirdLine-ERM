<?php
/**
 * GRC Risk Management Platform - cPanel Installer
 *
 * Upload this file + riskmtg.zip to public_html/riskmtg/
 * Then visit: https://yourdomain.com/riskmtg/install.php
 *
 * Structure after install:
 *   public_html/riskmtg/           <- web root (public/ contents)
 *   public_html/riskmtg/core/      <- Laravel app (not web-accessible)
 */

set_time_limit(600);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = $_GET['step'] ?? 'check';

// ─── Configuration ───────────────────────────────────────────────
$config = [
    'zip_file'    => __DIR__ . '/riskmtg.zip',
    'core_dir'    => __DIR__ . '/core',
    'public_dir'  => __DIR__,
    'db_host'     => '127.0.0.1',
    'db_port'     => '3306',
    'db_name'     => 'atheunqg_risky',
    'db_user'     => 'atheunqg_rskuser',
    'db_pass'     => '_f+yQ*^Bt&q$',
    'app_url'     => rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']), '/'),
];

// ─── HTML Header ─────────────────────────────────────────────────
function html_header($title) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>' . $title . '</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #1e293b; line-height: 1.6; padding: 2rem; }
        .container { max-width: 700px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.5rem; margin-bottom: .5rem; color: #0f172a; }
        h2 { font-size: 1.15rem; margin: 1.5rem 0 .75rem; color: #334155; }
        .ok { color: #16a34a; } .fail { color: #dc2626; } .warn { color: #d97706; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: .8rem; font-weight: 600; }
        .badge.ok { background: #dcfce7; } .badge.fail { background: #fee2e2; } .badge.warn { background: #fef3c7; }
        ul { list-style: none; padding: 0; }
        ul li { padding: .4rem 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .btn { display: inline-block; padding: .65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; text-decoration: none; margin-top: 1rem; }
        .btn:hover { background: #1d4ed8; }
        .btn.danger { background: #dc2626; } .btn.danger:hover { background: #b91c1c; }
        .btn.success { background: #16a34a; } .btn.success:hover { background: #15803d; }
        .log { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: .85rem; white-space: pre-wrap; max-height: 400px; overflow-y: auto; margin: 1rem 0; }
        .progress { background: #e2e8f0; border-radius: 8px; overflow: hidden; height: 24px; margin: 1rem 0; }
        .progress-bar { background: #2563eb; height: 100%; text-align: center; color: #fff; font-size: .75rem; line-height: 24px; transition: width .3s; }
    </style></head><body><div class="container">';
}
function html_footer() { echo '</div></body></html>'; }

function log_msg($msg, $class = '') {
    echo '<div class="' . $class . '">' . htmlspecialchars($msg) . '</div>';
    ob_flush(); flush();
}

// ═══════════════════════════════════════════════════════════════════
//  STEP 1: Environment Check
// ═══════════════════════════════════════════════════════════════════
if ($step === 'check') {
    html_header('GRC Risk Platform - Installation');
    echo '<div class="card">';
    echo '<h1>GRC Risk Management Platform</h1>';
    echo '<p style="color:#64748b">cPanel Shared Hosting Installer</p>';
    echo '</div>';

    echo '<div class="card"><h2>Environment Checks</h2><ul>';

    $pass = true;

    // PHP Version
    $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
    echo '<li>PHP Version: ' . PHP_VERSION . ' <span class="badge ' . ($phpOk ? 'ok' : 'fail') . '">' . ($phpOk ? 'OK' : 'Need 8.2+') . '</span></li>';
    $pass = $pass && $phpOk;

    // Required extensions
    $exts = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl'];
    foreach ($exts as $ext) {
        $loaded = extension_loaded($ext);
        echo '<li>' . $ext . ' <span class="badge ' . ($loaded ? 'ok' : 'fail') . '">' . ($loaded ? 'OK' : 'Missing') . '</span></li>';
        $pass = $pass && $loaded;
    }

    // ZIP extension
    $zipOk = extension_loaded('zip') || class_exists('ZipArchive');
    echo '<li>zip <span class="badge ' . ($zipOk ? 'ok' : 'fail') . '">' . ($zipOk ? 'OK' : 'Missing') . '</span></li>';
    $pass = $pass && $zipOk;

    // Zip file exists
    $zipExists = file_exists($config['zip_file']);
    echo '<li>riskmtg.zip <span class="badge ' . ($zipExists ? 'ok' : 'fail') . '">' . ($zipExists ? 'Found' : 'Not Found') . '</span></li>';
    $pass = $pass && $zipExists;

    // Writable
    $writable = is_writable(__DIR__);
    echo '<li>Directory writable <span class="badge ' . ($writable ? 'ok' : 'fail') . '">' . ($writable ? 'OK' : 'No') . '</span></li>';
    $pass = $pass && $writable;

    // Database
    $dbOk = false;
    try {
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk = true;
        echo '<li>Database connection <span class="badge ok">OK</span></li>';
    } catch (PDOException $e) {
        echo '<li>Database connection <span class="badge fail">Failed: ' . htmlspecialchars($e->getMessage()) . '</span></li>';
    }
    $pass = $pass && $dbOk;

    echo '</ul>';

    if ($pass) {
        echo '<a href="?step=install" class="btn success" onclick="this.textContent=\'Installing...\';this.style.pointerEvents=\'none\'">Install Now</a>';
    } else {
        echo '<p class="fail" style="margin-top:1rem;font-weight:600">Please fix the issues above before installing.</p>';
        echo '<a href="?step=check" class="btn">Re-check</a>';
    }

    echo '</div>';

    echo '<div class="card"><h2>Installation Details</h2><ul>';
    echo '<li>App URL <span style="font-family:monospace;font-size:.85rem">' . htmlspecialchars($config['app_url']) . '</span></li>';
    echo '<li>Database <span style="font-family:monospace;font-size:.85rem">' . htmlspecialchars($config['db_name']) . '</span></li>';
    echo '<li>Core path <span style="font-family:monospace;font-size:.85rem">' . htmlspecialchars($config['core_dir']) . '</span></li>';
    echo '</ul></div>';

    html_footer();
    exit;
}

// ═══════════════════════════════════════════════════════════════════
//  STEP 2: Install
// ═══════════════════════════════════════════════════════════════════
if ($step === 'install') {
    html_header('Installing GRC Risk Platform');
    echo '<div class="card"><h1>Installation Progress</h1>';
    echo '<div class="log" id="log">';
    ob_flush(); flush();

    $ok = true;

    // 2a. Extract ZIP
    log_msg("[1/7] Extracting riskmtg.zip ...");
    $zip = new ZipArchive();
    if ($zip->open($config['zip_file']) === true) {
        $zip->extractTo(__DIR__);
        $zip->close();
        log_msg("  Extracted successfully.");
    } else {
        log_msg("  FAILED to open zip file!", 'fail');
        $ok = false;
    }

    if ($ok) {
        // 2b. Create .env
        log_msg("\n[2/7] Creating .env file ...");
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $env = <<<ENV
APP_NAME="GRC Risk Platform"
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL={$config['app_url']}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST={$config['db_host']}
DB_PORT={$config['db_port']}
DB_DATABASE={$config['db_name']}
DB_USERNAME={$config['db_user']}
DB_PASSWORD="{$config['db_pass']}"

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/riskmtg
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log

VITE_APP_NAME="GRC Risk Platform"
ENV;
        file_put_contents($config['core_dir'] . '/.env', $env);
        log_msg("  .env created with production settings.");

        // 2c. Rewrite index.php paths
        log_msg("\n[3/7] Configuring index.php for cPanel structure ...");
        $indexContent = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// cPanel structure: core/ holds the Laravel app
$corePath = __DIR__ . '/core';

// ── Subdirectory fix ──────────────────────────────────────────
// Strip the /riskmtg prefix so Laravel sees clean route URIs.
// e.g. /riskmtg/risk/dashboard  →  /risk/dashboard
$subDir = '/riskmtg';
if (isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['ORIGINAL_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
    $uri = $_SERVER['REQUEST_URI'];
    if (strpos($uri, $subDir) === 0) {
        $uri = substr($uri, strlen($subDir)) ?: '/';
        $_SERVER['REQUEST_URI'] = $uri;
    }
}
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], $subDir) === 0) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}
if (isset($_SERVER['PHP_SELF']) && strpos($_SERVER['PHP_SELF'], $subDir) === 0) {
    $_SERVER['PHP_SELF'] = '/index.php';
}

// Maintenance mode
if (file_exists($maintenance = $corePath . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Composer autoloader
require $corePath . '/vendor/autoload.php';

// Bootstrap Laravel
/** @var Application $app */
$app = require_once $corePath . '/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
PHP;
        file_put_contents($config['public_dir'] . '/index.php', $indexContent);
        log_msg("  index.php updated.");

        // 2d. Fix storage & bootstrap/cache permissions
        log_msg("\n[4/7] Setting directory permissions ...");
        $dirs = [
            $config['core_dir'] . '/storage',
            $config['core_dir'] . '/storage/app',
            $config['core_dir'] . '/storage/app/public',
            $config['core_dir'] . '/storage/app/private',
            $config['core_dir'] . '/storage/framework',
            $config['core_dir'] . '/storage/framework/cache',
            $config['core_dir'] . '/storage/framework/cache/data',
            $config['core_dir'] . '/storage/framework/sessions',
            $config['core_dir'] . '/storage/framework/views',
            $config['core_dir'] . '/storage/logs',
            $config['core_dir'] . '/bootstrap/cache',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            chmod($dir, 0755);
        }
        log_msg("  Permissions set.");

        // 2e. Create storage symlink
        log_msg("\n[5/7] Creating storage symlink ...");
        $storageLinkTarget = $config['core_dir'] . '/storage/app/public';
        $storageLinkPath = $config['public_dir'] . '/storage';
        if (is_link($storageLinkPath)) {
            unlink($storageLinkPath);
        }
        if (!is_dir($storageLinkPath)) {
            @symlink($storageLinkTarget, $storageLinkPath);
            if (is_link($storageLinkPath)) {
                log_msg("  Storage symlink created.");
            } else {
                log_msg("  WARNING: Could not create symlink (copy fallback will be needed).", 'warn');
            }
        }

        // 2f. Detect PHP binary path
        $phpBin = PHP_BINARY ?: 'php';
        // On cPanel the SAPI binary is often "litespeed" or "cgi", find CLI binary
        if (php_sapi_name() !== 'cli') {
            // Try common cPanel CLI paths
            $tryPaths = [
                '/usr/local/bin/php',
                '/usr/bin/php',
                '/usr/local/bin/ea-php82',
                '/usr/local/bin/ea-php83',
                '/usr/local/bin/ea-php84',
            ];
            foreach ($tryPaths as $p) {
                if (is_file($p) && is_executable($p)) {
                    $phpBin = $p;
                    break;
                }
            }
        }
        log_msg("\n[6/7] Running database migrations ...");
        log_msg("  PHP binary: " . $phpBin);

        $artisan = $config['core_dir'] . '/artisan';

        // Helper to run artisan commands
        $runArtisan = function($cmd) use ($phpBin, $artisan, &$ok) {
            $fullCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($artisan) . ' ' . $cmd . ' 2>&1';
            $output = [];
            $code = 0;
            exec($fullCmd, $output, $code);
            $result = implode("\n", $output);
            if ($code !== 0) {
                log_msg("  COMMAND FAILED (exit $code): $cmd", 'fail');
                log_msg("  " . $result, 'fail');
                $ok = false;
                return false;
            }
            if (trim($result)) {
                log_msg("  " . trim($result));
            }
            return true;
        };

        try {
            // Clear any stale caches
            $runArtisan('config:clear');
            log_msg("  Config cache cleared.");

            // Run migrations
            if ($runArtisan('migrate --force')) {
                log_msg("  Migrations completed.");
            }

            // Seed database
            if ($ok) {
                log_msg("\n[7/7] Seeding database ...");
                if ($runArtisan('db:seed --force')) {
                    log_msg("  Database seeded.");
                }
            }

            // Cache for production (skip route:cache — it bakes absolute paths
            // that break on shared hosting with subdirectory deployments)
            if ($ok) {
                $runArtisan('config:cache');
                $runArtisan('view:cache');
                log_msg("\n  Production caches generated.");
            }

        } catch (\Throwable $e) {
            log_msg("\n  Error: " . $e->getMessage(), 'fail');
            $ok = false;
        }
    }

    echo '</div>'; // close .log

    if ($ok) {
        echo '<div style="margin-top:1rem;padding:1rem;background:#dcfce7;border-radius:8px;color:#166534">';
        echo '<strong>Installation Complete!</strong><br>';
        echo 'Login: <strong>admin@risk.test</strong> / <strong>password</strong><br>';
        echo 'URL: <a href="' . htmlspecialchars($config['app_url']) . '">' . htmlspecialchars($config['app_url']) . '</a>';
        echo '</div>';
        echo '<a href="?step=cleanup" class="btn danger" style="margin-top:1rem">Clean Up Install Files</a>';
    } else {
        echo '<div style="margin-top:1rem;padding:1rem;background:#fee2e2;border-radius:8px;color:#991b1b">';
        echo '<strong>Installation encountered errors.</strong> Check the log above.';
        echo '</div>';
        echo '<a href="?step=check" class="btn">Back to Checks</a>';
    }

    echo '</div>';
    html_footer();
    exit;
}

// ═══════════════════════════════════════════════════════════════════
//  STEP 3: Cleanup
// ═══════════════════════════════════════════════════════════════════
if ($step === 'cleanup') {
    // Remove install files
    @unlink($config['zip_file']);
    @unlink(__FILE__);

    header('Location: ' . $config['app_url']);
    exit;
}
