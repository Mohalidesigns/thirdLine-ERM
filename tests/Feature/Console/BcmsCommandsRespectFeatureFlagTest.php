<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every `bcms:` command must no-op when the module is switched off.
 *
 * BCMS ships dark: `features.bcms` defaults to false and all 167 of its routes
 * 404 without it. But that was enforced at the HTTP boundary and NOWHERE ELSE.
 * Six of the eight commands guarded themselves; `bcms:dispatch-reminders` and
 * `bcms:watchdog` did not, and both are scheduled hourly. The first of those
 * does not merely read — it calls `markOverdue()` and `dispatchDue()`, so on
 * any installation with BCMS off and rows in the tables (a pilot, a restored
 * dump, a flag flipped and flipped back) an hourly no-op becomes an hourly
 * send to real staff.
 *
 * This test enumerates the commands from Artisan rather than listing them, so
 * a ninth command added tomorrow is covered the day it is registered. A guard
 * on six of eight is exactly how the seventh gets forgotten.
 *
 * It asserts the GUARD fired, not merely that nothing happened: an unguarded
 * command against empty tables also exits zero and also changes nothing, so
 * "no exception, no rows" would pass for both and prove neither. The switched-
 * off notice is the only thing that distinguishes them.
 */
class BcmsCommandsRespectFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Commands legitimately exempt from the guard, with the reason.
     *
     * `bcms:verify-schema` compares the live schema against the frozen
     * manifest. It is a build- and deploy-time integrity check on tables that
     * exist whether or not the module is switched on, and refusing to run it
     * while the flag is off would remove the check from exactly the
     * installations most likely to have drifted.
     *
     * @var list<string>
     */
    private const EXEMPT = ['bcms:verify-schema'];

    #[Test]
    public function every_bcms_command_no_ops_when_the_module_is_off(): void
    {
        Config::set('features.bcms', false);

        $commands = $this->bcmsCommands();

        $this->assertNotEmpty($commands, 'No bcms: commands found — the enumeration has broken, not the module.');

        foreach ($commands as $name) {
            $exit = Artisan::call($name);
            $output = Artisan::output();

            $this->assertSame(0, $exit, "[{$name}] should exit zero when BCMS is off, not {$exit}.");

            $this->assertStringContainsString(
                'switched off',
                $output,
                "[{$name}] ran without saying the module is switched off.\n".
                'Exiting zero is not enough: with empty tables an UNGUARDED command also exits zero and also does '.
                "nothing, so only the notice distinguishes a guard from a coincidence.\n".
                "Add the same early return the other bcms: commands use:\n".
                "    if (! config('features.bcms')) { \$this->line('The BCMS module is switched off; …'); return self::SUCCESS; }"
            );
        }
    }

    #[Test]
    public function the_guard_is_not_simply_always_on(): void
    {
        // The counterpart to the test above. If a command printed "switched
        // off" unconditionally — or if config() were misread so the guard
        // always fired — the first test would pass while the module was
        // permanently disabled. Turning the flag on must change the behaviour.
        Config::set('features.bcms', true);

        Artisan::call('bcms:watchdog');

        $this->assertStringNotContainsString(
            'switched off',
            Artisan::output(),
            'bcms:watchdog reports the module switched off even when features.bcms is TRUE, '.
            'so the guard is not reading the flag — it is always firing.'
        );
    }

    /**
     * @return list<string>
     */
    private function bcmsCommands(): array
    {
        $names = array_keys(Artisan::all());

        return array_values(array_filter(
            $names,
            fn (string $name) => str_starts_with($name, 'bcms:') && ! in_array($name, self::EXEMPT, true)
        ));
    }
}
