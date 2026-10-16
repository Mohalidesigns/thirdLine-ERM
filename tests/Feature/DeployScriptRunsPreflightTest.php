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

    /**
     * Offsets of a command on executable lines only: the script's comments and
     * its echoed messages name these commands too.
     *
     * @return list<int>
     */
    private function commandOffsets(string $needle): array
    {
        $offsets = [];
        $position = 0;

        foreach (preg_split('/(?<=\n)/', $this->script()) as $line) {
            if (! preg_match('/^\s*(#|echo\b)/', $line) && str_contains($line, $needle)) {
                $offsets[] = $position + strpos($line, $needle);
            }

            $position += strlen($line);
        }

        return $offsets;
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

        // ADR 0029 put one restart BEFORE preflight on purpose: the worker drain
        // inside the maintenance window, which the ordering test below pins. The
        // restarts that put new code into service are php-fpm and the LAST
        // risk-queue restart, so those are what preflight must precede.
        $preflight = strpos($script, 'php artisan app:preflight');
        $restart = min(
            $this->commandOffsets('systemctl restart php8.4-fpm')[0] ?? PHP_INT_MAX,
            (int) last($this->commandOffsets('systemctl restart risk-queue') ?: [PHP_INT_MAX]),
        );

        $this->assertNotFalse($preflight);
        $this->assertNotSame(PHP_INT_MAX, $restart);

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

    #[Test]
    public function the_deploy_runs_inside_a_maintenance_window_in_the_adr_0029_order(): void
    {
        $this->script();

        $drain = $this->commandOffsets('systemctl restart risk-queue');
        $this->assertCount(2, $drain, 'Expected the drain after `down` and the final restart after preflight.');

        $steps = [
            'git fetch' => $this->commandOffsets('git fetch')[0] ?? null,
            'artisan down' => $this->commandOffsets('artisan down')[0] ?? null,
            'ERR trap' => $this->commandOffsets("trap '")[0] ?? null,
            'worker drain' => $drain[0],
            'git reset' => $this->commandOffsets('git reset')[0] ?? null,
            'composer install' => $this->commandOffsets('composer install')[0] ?? null,
            'npm run build' => $this->commandOffsets('npm run build')[0] ?? null,
            'schedule:run wait' => $this->commandOffsets("pgrep -f 'artisan schedule:run'")[0] ?? null,
            'artisan migrate' => $this->commandOffsets('artisan migrate')[0] ?? null,
            'artisan db:seed' => $this->commandOffsets('artisan db:seed')[0] ?? null,
            'artisan config:cache' => $this->commandOffsets('artisan config:cache')[0] ?? null,
            'app:preflight' => $this->commandOffsets('artisan app:preflight')[0] ?? null,
            'php-fpm restart' => $this->commandOffsets('systemctl restart php8.4-fpm')[0] ?? null,
            'final worker restart' => $drain[1],
            'artisan up' => $this->commandOffsets('artisan up')[0] ?? null,
        ];

        foreach ($steps as $step => $offset) {
            $this->assertNotNull($offset, "scripts/deploy.sh no longer runs [{$step}].");
        }

        $names = array_keys($steps);
        $offsets = array_values($steps);

        for ($i = 1; $i < count($offsets); $i++) {
            $this->assertLessThan(
                $offsets[$i],
                $offsets[$i - 1],
                "ADR 0029: [{$names[$i - 1]}] must run before [{$names[$i]}]."
            );
        }

        // THE load-bearing pair. `composer install` deletes bootstrap/cache/config.php, so from then on
        // every new process reads config/database.php from disk: the pin is live. If `down` came after
        // it, writers would put UTC into tables the conversion has not reached yet.
        $this->assertLessThan(
            $steps['composer install'],
            $steps['artisan down'],
            'The maintenance window must open BEFORE composer install: that is where the new configuration goes live.'
        );

        $this->assertCount(1, $this->commandOffsets('artisan up'), 'artisan up must appear exactly once, as the last step.');
        $this->assertCount(1, $this->commandOffsets('artisan down'));

        // A bare `exit 1` does not fire the ERR trap, so a timed-out schedule:run wait must go
        // through `false` and say the site is in maintenance mode.
        $this->assertMatchesRegularExpression(
            '/schedule:run still running.*\n.*MAINTENANCE MODE.*\n\s*false\n/',
            $script = $this->script(),
            'The schedule:run wait must fail through the ERR trap (false), naming the maintenance state.'
        );
        $this->assertStringContainsString('command_timeout: 40m', file_get_contents(base_path('.github/workflows/deploy.yml')));

        // Not reachable from the preflight failure branch: `up` sits after the branch's `exit 1`.
        $this->assertLessThan(
            strpos($script, 'echo "==> Maintenance mode off"'),
            strrpos($script, 'exit 1'),
            'A failed preflight must exit before the site is brought back up.'
        );
    }
}
