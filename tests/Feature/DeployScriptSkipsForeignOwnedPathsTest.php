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
 * DeployScriptRuntimePermissionsTest runs the real step, but a test cannot
 * create a path another user owns without root, so this static check is what
 * stops a tidy-up back to a bare `find storage ... -exec chmod`, which would
 * pass the whole suite and fail the next deploy on the server.
 */
class DeployScriptSkipsForeignOwnedPathsTest extends TestCase
{
    #[Test]
    public function every_chmod_in_the_deploy_script_skips_paths_the_deploy_user_does_not_own(): void
    {
        $path = base_path('scripts/deploy.sh');
        $this->assertFileExists($path);

        // One entry per shell command: a `\` at the end of a line continues it,
        // so a multi-line find is judged whole, not line by line.
        $script = preg_replace('/\\\\\n\s*/', ' ', (string) file_get_contents($path));
        $chmods = array_filter(
            explode("\n", $script),
            // Commands that run chmod, not a message that mentions it.
            fn (string $c) => ! str_starts_with(ltrim($c), '#')
                && preg_match('/-exec\s+chmod\b|^\s*(sudo\s+)?chmod\b/', $c) === 1,
        );

        $this->assertNotEmpty($chmods, 'scripts/deploy.sh no longer re-applies runtime-writable permissions.');

        foreach ($chmods as $command) {
            $guard = strpos($command, '! -user "$DEPLOY_USER" -prune');
            $this->assertTrue(
                $guard !== false && $guard < strpos($command, 'chmod'),
                'This chmod does not first prune paths owned by another user, so a www-data-owned directory under '.
                "storage aborts the deploy before preflight and the restart:\n    ".trim($command)
            );
        }
    }
}
