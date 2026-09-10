<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
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

    #[Test]
    public function it_fails_rather_than_warns_when_the_certification_document_is_missing(): void
    {
        Config::set('features.bcms', true);
        Config::set('preflight.bcms_handoff', 'docs/bcms/this-document-does-not-exist.md');

        // The first version of this check WARNED here, and `handle()` only
        // fails a deploy on failures — so deleting, moving or renaming one
        // markdown file silently turned a hard release gate into a notice
        // nobody reads. A gate that cannot confirm certification must refuse.
        //
        // ASSERT ON THE ROW, NOT ON THE EXIT CODE. Preflight reports many
        // checks and several others fail in a test environment, so
        // `assertFailed()` is true whether this check warns or fails — an
        // earlier version of this test asserted exactly that and passed with
        // the defect still present. Only the status printed against THIS row
        // distinguishes the two.
        Artisan::call('app:preflight', ['--allow-local' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/Uncertified modules\s*\|\s*FAIL/',
            $output,
            "The missing-document branch did not FAIL.\nA warning does not block a deploy — `handle()` only returns ".
            'FAILURE when $failures > 0 — so a gate that cannot confirm certification would let an uncertified '.
            "module be served.\n\nPreflight output was:\n".$output
        );
    }

    #[Test]
    public function a_missing_document_is_harmless_while_the_module_is_off(): void
    {
        Config::set('features.bcms', false);
        Config::set('preflight.bcms_handoff', 'docs/bcms/this-document-does-not-exist.md');

        // Symmetry matters: the gate must not fail a deploy over a document it
        // has no reason to read. Nothing uncertified is being served, so there
        // is nothing to confirm. Asserted on the row for the same reason as
        // above — the overall exit code cannot tell these cases apart.
        Artisan::call('app:preflight', ['--allow-local' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/Uncertified modules\s*\|\s*PASS/',
            $output,
            "With BCMS off, a missing certification document is irrelevant and this row must PASS.\n\n".$output
        );
    }

    #[Test]
    public function a_null_handoff_path_falls_back_instead_of_crashing(): void
    {
        Config::set('features.bcms', true);
        Config::set('preflight.bcms_handoff', null);

        // The shape a future config/preflight.php would produce if it declared
        // 'bcms_handoff' => env('PREFLIGHT_BCMS_HANDOFF') with the env var
        // unset: the key EXISTS holding null, so config()'s own default is
        // never reached. Cast to '' that resolves base_path() to the app root,
        // File::exists() says true for a directory, and File::get() throws.
        //
        // The gate must fall back to the real document and report normally,
        // not crash. A release gate that dies is not a release gate.
        Artisan::call('app:preflight', ['--allow-local' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression(
            '/Uncertified modules\s*\|\s*FAIL/',
            $output,
            'A null configured path should fall back to the real handoff document, find the uncertified marker '.
            "and FAIL cleanly.\n\n".$output
        );
        $this->assertStringNotContainsString('is missing, so its certification', $output,
            'It fell through to the missing-file branch, so the fallback did not happen.');
    }
}
