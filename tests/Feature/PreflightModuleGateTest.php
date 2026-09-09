<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Preflight must refuse to serve a module that has not passed its gates.
 *
 * BCMS Phase 7 — nine EMNS channel adapters and two unauthenticated provider
 * callbacks — was merged without qa-engineer or code-reviewer running against
 * it. That was accepted only because the module ships dark: `features.bcms`
 * defaults to false, all 167 BCMS routes 404, and every `bcms:` command now
 * returns immediately.
 *
 * "Ships dark" is worth exactly as much as whatever enforces it. A note in a
 * handoff document is a convention, and a convention lasts until the first
 * person who needs a demo environment. This is the enforcement.
 */
class PreflightModuleGateTest extends TestCase
{
    #[Test]
    public function it_lists_which_modules_this_installation_actually_serves(): void
    {
        Config::set('features.tprm', true);

        // Nothing else tells an operator. Every flag defaults to false, so an
        // installation that never sets FEATURE_TPRM 404s every TPRM route and
        // the module reads as absent rather than switched off.
        $this->artisan('app:preflight', ['--allow-local' => true])
            ->expectsOutputToContain('Modules enabled');
    }

    #[Test]
    public function it_passes_while_the_uncertified_module_is_switched_off(): void
    {
        Config::set('features.bcms', false);

        $this->artisan('app:preflight', ['--allow-local' => true])
            ->doesntExpectOutputToContain('BCMS IS ON');
    }

    #[Test]
    public function it_fails_the_deploy_when_the_uncertified_module_is_switched_on(): void
    {
        Config::set('features.bcms', true);

        // A non-zero exit is the point: preflight runs as the last step of a
        // deploy, so this stops the pipeline rather than merely printing a
        // grumble nobody reads.
        $this->artisan('app:preflight', ['--allow-local' => true])
            ->expectsOutputToContain('BCMS IS ON')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_reads_the_handoff_rather_than_a_constant(): void
    {
        // The certification state lives in docs/bcms/phase-7-handoff.md, so
        // certifying Phase 7 is one deliberate edit in the document that
        // records the decision — and cannot be done by accident from
        // Preflight.php. This asserts the wiring: if the marker were ignored,
        // or read from somewhere else, turning the flag on would not fail.
        $handoff = base_path('docs/bcms/phase-7-handoff.md');

        $this->assertFileExists($handoff);
        $this->assertStringContainsString(
            'THE QA GATE AND THE REVIEW GATE HAVE NOT BEEN RUN',
            file_get_contents($handoff),
            'The marker is gone from the Phase 7 handoff. If Phase 7 really has passed both gates that is correct '.
            'and this test should be deleted with it — but if the line was tidied away, the deploy gate it drives '.
            'has been silently disarmed.'
        );
    }
}
