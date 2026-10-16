<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deploy's permission step must not trip over files the app created.
 *
 * `scripts/deploy.sh` runs as `deploy` under `set -e`. The app and the queue
 * worker run as www-data and create directories under storage of their own;
 * only a file's owner may chmod it. On 2026-10-16 the deploy of 6ae4611 hit
 * www-data-owned `storage/app/private/rcsa` and stopped after migrate but
 * before preflight and the service restart, leaving the queue worker on the
 * previous release.
 *
 * A shell script has no other test, so a tidy-up back to a bare
 * `find storage ... -exec chmod` would pass the whole suite and fail the next
 * deploy on the server.
 */
class DeployScriptSkipsForeignOwnedPathsTest extends TestCase
{
    #[Test]
    public function every_chmod_in_the_deploy_script_skips_paths_the_deploy_user_does_not_own(): void
    {
        $path = base_path('scripts/deploy.sh');
        $this->assertFileExists($path);

        $chmods = array_filter(
            explode("\n", file_get_contents($path)),
            fn (string $line) => str_contains($line, 'chmod') && ! str_starts_with(ltrim($line), '#'),
        );

        $this->assertNotEmpty($chmods, 'scripts/deploy.sh no longer re-applies runtime-writable permissions.');

        foreach ($chmods as $line) {
            $this->assertMatchesRegularExpression(
                '/!\s+-user\s+"\$DEPLOY_USER"\s+-prune\s+-o\b/',
                $line,
                'This chmod does not skip paths owned by another user, so a www-data-owned directory under storage '.
                "aborts the deploy before preflight and the restart:\n    ".trim($line)
            );
        }
    }
}
