<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deploy must actually run the preflight check.
 *
 * `app:preflight` refuses APP_DEBUG in production, an unhardened session
 * cookie, an unguarded route, a missing audit hash chain, and a module whose
 * gates have not been run. Until 2026-09-10 nothing invoked it: `DEPLOYMENT.md`
 * asked a human to type one line, and `scripts/deploy.sh` — the thing that
 * actually deploys — went straight from caching config to restarting php-fpm.
 *
 * Every control in that command was therefore worth exactly what a person
 * remembering was worth, which is the same as no control at all on the day it
 * matters. Found by code-reviewer, who called wiring it in the cheapest real
 * risk reduction available on the branch.
 *
 * This test exists because the wiring can be deleted as quietly as it was
 * absent. A refactor that drops one line from a shell script produces no
 * failing test anywhere else in this suite.
 */
class DeployScriptRunsPreflightTest extends TestCase
{
    private function script(): string
    {
        $path = base_path('scripts/deploy.sh');

        $this->assertFileExists($path, 'scripts/deploy.sh is gone; the deploy pipeline calls it by name over SSH.');

        return file_get_contents($path);
    }

    #[Test]
    public function the_deploy_script_invokes_preflight(): void
    {
        $this->assertMatchesRegularExpression(
            '/php artisan app:preflight/',
            $this->script(),
            'scripts/deploy.sh no longer runs app:preflight. Every check that command performs — production '.
            'APP_DEBUG, session hardening, unguarded routes, the audit hash chain, uncertified modules — is '.
            'unenforced again, and the deploy will restart services regardless of what it would have found.'
        );
    }

    #[Test]
    public function a_failed_preflight_stops_the_deploy(): void
    {
        $script = $this->script();

        // Invoking it is not enough: a bare call whose exit code is ignored
        // prints a report nobody reads and restarts the services anyway. The
        // script must branch on the result and leave with a non-zero status.
        $this->assertMatchesRegularExpression(
            '/if\s*!\s*php artisan app:preflight/',
            $script,
            'app:preflight is invoked but its exit code is not tested, so a failed preflight would not stop the deploy.'
        );

        $this->assertMatchesRegularExpression(
            '/PREFLIGHT FAILED.*\n(.*\n)*?\s*exit 1/',
            $script,
            'The failure branch does not exit non-zero, so the script would continue to the service restart.'
        );
    }

    #[Test]
    public function preflight_runs_before_the_services_are_restarted(): void
    {
        $script = $this->script();

        $preflight = strpos($script, 'php artisan app:preflight');
        $restart = strpos($script, 'systemctl restart');

        $this->assertNotFalse($preflight);
        $this->assertNotFalse($restart);

        // Order is the whole point. Preflight after the restart would report on
        // a release already in service, which is a post-mortem rather than a
        // gate.
        $this->assertLessThan(
            $restart,
            $preflight,
            'app:preflight runs AFTER the services are restarted, so it can only describe a release that is '.
            'already serving traffic. It has to be able to decline.'
        );
    }

    #[Test]
    public function preflight_runs_after_migrations_so_it_sees_this_release(): void
    {
        $script = $this->script();

        $migrate = strpos($script, 'php artisan migrate');
        $preflight = strpos($script, 'php artisan app:preflight');

        $this->assertNotFalse($migrate);

        // Several checks read the database — the audit hash chain, and the
        // module gate through cached config. Running before the migration
        // would test the previous release's schema and pass on a deploy that
        // breaks the new one.
        $this->assertLessThan(
            $preflight,
            $migrate,
            'app:preflight runs BEFORE migrations, so its database checks describe the outgoing release.'
        );
    }
}
