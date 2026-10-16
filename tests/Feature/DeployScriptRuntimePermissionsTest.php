<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The deploy's permissions step must never stop the deploy.
 *
 * On 2026-10-05 the production deploy of PR #12 died at "Ensure runtime-writable
 * permissions" with `find: 'storage/app/private/rcsa': Permission denied`. RCSA
 * v2 had been enabled the day before; php-fpm, running as www-data, created that
 * directory 0700 (Laravel's private-directory default), the `deploy` user could
 * not list it, and the bare `find … -exec chmod` under `set -euo pipefail`
 * exited 1 — before preflight and before the restart. Every later deploy would
 * have died at the same line.
 *
 * These tests EXECUTE the real step — `scripts/deploy.sh --permissions-only DIR`
 * runs the same function the deploy calls, under the script's own `set -euo
 * pipefail` — against a throwaway tree. Reading the script text would pass on
 * the broken version: it was syntactically fine and correct on every tree
 * without a directory owned by someone else.
 *
 * A test process cannot create a www-data-owned directory without root. Mode
 * 0000 on our own directory is NOT enough on its own: find visits a directory
 * before descending, the old step chmod'ed it 2775 first, and then read it
 * happily — that simulation passes on the broken script. What made production
 * fail was the pair "cannot list it" AND "cannot chmod it, not the owner". So
 * the directory is 0000 and a `chmod` shim placed first on PATH refuses it with
 * the kernel's own EPERM message, while passing every other path to the real
 * chmod. Against the pre-fix step this reproduces the production output line
 * for line (`find: …/rcsa: Permission denied`, exit 1). Skipped as root, for
 * whom no mode is unreadable.
 */
class DeployScriptRuntimePermissionsTest extends TestCase
{
    private string $root;

    private string $shim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/deploy-perms-'.bin2hex(random_bytes(6));

        foreach ([
            'storage/app/private/rcsa/imports',
            'storage/app/private/issue-attachments/41',
            'storage/app/public/branding',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ] as $dir) {
            mkdir("{$this->root}/{$dir}", 0700, true);
        }

        foreach ([
            'storage/app/private/rcsa/imports/workbook.xlsx',
            'storage/app/private/issue-attachments/41/evidence.pdf',
            'storage/app/public/branding/logo.png',
            'storage/logs/laravel.log',
            'bootstrap/cache/packages.php',
        ] as $file) {
            file_put_contents("{$this->root}/{$file}", 'x');
            chmod("{$this->root}/{$file}", 0600);
        }

        $this->installChmodShim();
    }

    /**
     * A `chmod` that behaves as if every path ending in `/rcsa` belonged to
     * another user, and is the real chmod for everything else.
     */
    private function installChmodShim(): void
    {
        $which = new Process(['sh', '-c', 'command -v chmod']);
        $which->mustRun();
        $real = trim($which->getOutput());

        $this->shim = sys_get_temp_dir().'/deploy-perms-shim-'.bin2hex(random_bytes(6));
        mkdir($this->shim, 0700);

        file_put_contents("{$this->shim}/chmod", <<<SH
            #!/bin/sh
            mode="\$1"; shift; rc=0
            for p in "\$@"; do
              case "\$p" in
                */rcsa) echo "chmod: changing permissions of '\$p': Operation not permitted" >&2; rc=1 ;;
                *) '{$real}' "\$mode" "\$p" || rc=1 ;;
              esac
            done
            exit \$rc
            SH);
        chmod("{$this->shim}/chmod", 0755);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            // Give back what the test took away, or the delete cannot descend.
            (new Process(['chmod', '-R', 'u+rwx', $this->root]))->run();
            File::deleteDirectory($this->root);
        }

        File::deleteDirectory($this->shim);

        parent::tearDown();
    }

    private function runStep(): Process
    {
        $process = new Process(
            ['bash', base_path('scripts/deploy.sh'), '--permissions-only', $this->root],
            base_path(),
            ['PATH' => $this->shim.PATH_SEPARATOR.(string) getenv('PATH')],
            null,
            60,
        );
        $process->run();

        return $process;
    }

    /**
     * Mode of every entry under the tree, keyed by relative path.
     *
     * @return array<string, string>
     */
    private function modes(): array
    {
        clearstatcache();
        $modes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $modes[substr($entry->getPathname(), strlen($this->root) + 1)] = sprintf('%04o', fileperms($entry->getPathname()) & 07777);
        }
        ksort($modes);

        return $modes;
    }

    private function mode(string $relative): int
    {
        clearstatcache();

        return fileperms("{$this->root}/{$relative}") & 0777;
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: no directory mode is unreadable, so the failure cannot be reproduced.');
        }
    }

    #[Test]
    public function an_unlistable_directory_is_skipped_and_named_not_fatal(): void
    {
        $this->skipIfRoot();

        chmod("{$this->root}/storage/app/private/rcsa", 0000);

        $step = $this->runStep();
        $output = $step->getOutput().$step->getErrorOutput();

        $this->assertSame(
            0,
            $step->getExitCode(),
            'The permissions step exited non-zero on a tree with an unlistable directory, so the deploy would abort '.
            "before preflight and the restart — the 2026-10-05 production failure.\n{$output}"
        );

        $this->assertStringContainsString('WARNING', $output);
        $this->assertStringContainsString(
            'storage/app/private/rcsa',
            $output,
            'The unreachable directory was skipped silently. An operator has to be told which path to fix as root.'
        );

        // Pruned, not touched — and nothing below it was attempted.
        $this->assertSame(0000, $this->mode('storage/app/private/rcsa'));
    }

    #[Test]
    public function the_rest_of_the_tree_is_still_fixed_and_private_evidence_is_never_world_readable(): void
    {
        $this->skipIfRoot();

        chmod("{$this->root}/storage/app/private/rcsa", 0000);

        $step = $this->runStep();
        $this->assertSame(0, $step->getExitCode(), $step->getOutput().$step->getErrorOutput());

        // The `local` disk root holds every module's evidence. Owner+group only.
        // (Setgid is not asserted: a non-member of the directory's group cannot
        // set it, and a temp directory's group is not ours on every machine.)
        $this->assertSame(0770, $this->mode('storage/app/private'));
        $this->assertSame(0770, $this->mode('storage/app/private/issue-attachments/41'));
        $this->assertSame(0660, $this->mode('storage/app/private/issue-attachments/41/evidence.pdf'));

        // Everything else keeps the long-standing group-writable modes.
        $this->assertSame(0775, $this->mode('storage'));
        $this->assertSame(0775, $this->mode('storage/app/public/branding'));
        $this->assertSame(0664, $this->mode('storage/app/public/branding/logo.png'));
        $this->assertSame(0775, $this->mode('storage/framework/views'));
        $this->assertSame(0664, $this->mode('storage/logs/laravel.log'));
        $this->assertSame(0775, $this->mode('bootstrap/cache'));
        $this->assertSame(0664, $this->mode('bootstrap/cache/packages.php'));

        // The load-bearing negative: nothing reachable under the private root
        // grants "other" anything.
        chmod("{$this->root}/storage/app/private/rcsa", 0700);
        $leaks = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator("{$this->root}/storage/app/private", \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ((fileperms($entry->getPathname()) & 0007) !== 0) {
                $leaks[] = $entry->getPathname();
            }
        }
        $this->assertSame([], $leaks, 'The permissions step made private evidence world-accessible.');
    }

    #[Test]
    public function a_find_error_is_reported_and_does_not_abort(): void
    {
        // A missing start point makes find exit 1 the same way the denied
        // directory did, and stands in for the live-host race — a session or
        // cache file deleted between find listing it and chmod reaching it.
        File::deleteDirectory("{$this->root}/bootstrap");

        $step = $this->runStep();
        $output = $step->getOutput().$step->getErrorOutput();

        $this->assertSame(0, $step->getExitCode(), "A find error aborted the permissions step.\n{$output}");
        $this->assertStringContainsString('continuing', $output);
        $this->assertStringContainsString('bootstrap/cache', $output);

        // And it still did its work on what it could reach.
        $this->assertSame(0770, $this->mode('storage/app/private'));
    }

    #[Test]
    public function entries_the_deploy_user_does_not_own_are_never_chmoded(): void
    {
        // On the host, everything php-fpm and the queue worker create belongs to
        // www-data, and the step must leave it alone: `-user "$me"` is the only
        // thing keeping a 2775/0664 sweep off files that are not deploy's. A
        // temp tree is always wholly the test user's, so to pin that filter the
        // step is told it is someone who owns none of it. `$me` comes from
        // `id -un` resolved through PATH, so an `id` placed first on PATH that
        // answers `-un` with `nobody` (a real account on Linux and macOS, so
        // find accepts the name rather than aborting on an unknown user) does
        // exactly that and is the real id for anything else.
        $which = new Process(['sh', '-c', 'command -v id']);
        $which->mustRun();
        $realId = trim($which->getOutput());

        $probe = new Process([$realId, '-u', 'nobody']);
        if ($probe->run() !== 0) {
            $this->markTestSkipped('No `nobody` account on this host to stand in for a non-owner.');
        }

        file_put_contents("{$this->shim}/id", <<<SH
            #!/bin/sh
            if [ "\$1" = "-un" ] && [ "\$#" -eq 1 ]; then echo nobody; exit 0; fi
            exec '{$realId}' "\$@"
            SH);
        chmod("{$this->shim}/id", 0755);

        $before = $this->modes();

        $step = $this->runStep();
        $output = $step->getOutput().$step->getErrorOutput();

        $this->assertSame(0, $step->getExitCode(), $output);

        $this->assertSame(
            $before,
            $this->modes(),
            'The permissions step chmod\'ed entries the deploy user does not own. On the host those belong to '.
            'www-data; the `-user "$me"` filter in ensure_runtime_permissions is gone or broken.'
        );

        // And no warning: a find that aborted on its own arguments (an unknown
        // user name, say) would also chmod nothing, and must not pass this
        // test for the wrong reason.
        $this->assertStringNotContainsString('WARNING', $output);
    }

    #[Test]
    public function the_deploy_calls_the_tolerant_step_and_not_a_bare_find(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertDoesNotMatchRegularExpression(
            '/^find storage bootstrap\/cache .*-exec chmod/m',
            $script,
            'A bare top-level `find … -exec chmod` is back in scripts/deploy.sh. Under `set -e` it aborts the whole '.
            'deploy on the first directory the deploy user cannot list.'
        );

        $banner = strpos($script, '==> Ensure runtime-writable permissions');
        $this->assertNotFalse($banner);
        $this->assertMatchesRegularExpression(
            '/==> Ensure runtime-writable permissions"\s*\nensure_runtime_permissions\s*\n/',
            $script,
            'The permissions banner is no longer followed by the ensure_runtime_permissions call.'
        );

        // Ordering is unchanged: after the caches it re-permissions, before
        // preflight it must already have run.
        $this->assertLessThan($banner, strpos($script, 'php artisan view:cache'));
        $this->assertLessThan(strpos($script, 'if ! php artisan app:preflight'), $banner);
    }
}
