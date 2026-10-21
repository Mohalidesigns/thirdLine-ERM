<?php

namespace Tests\Unit\Support;

use App\Support\MigrationWindow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The window is a depth counter, not a flag. A migrate run started from inside
 * another (a migration calling `Artisan::call('migrate', ...)`) fires its own
 * `MigrationsStarted` / `MigrationsEnded` pair; with a boolean the inner
 * `MigrationsEnded` closed the window while the outer run was still applying
 * migrations, and every data migration after the nested call fired webhooks
 * and workflow triggers again — the 02cf6df upgrade failure, one level down.
 *
 * No container and no database: the class is plain state. That the migrator's
 * events reach it is proved in `ObserversAreSilentDuringMigrationsTest`.
 */
class MigrationWindowTest extends TestCase
{
    #[Test]
    public function it_starts_closed(): void
    {
        $window = new MigrationWindow;

        $this->assertFalse($window->isOpen());
        $this->assertSame(0, $window->depth());
    }

    #[Test]
    public function a_nested_runs_close_does_not_close_the_outer_window(): void
    {
        $window = new MigrationWindow;

        $window->open();   // outer `migrate`
        $window->open();   // a migration calls `migrate` again
        $this->assertSame(2, $window->depth());

        $window->close();  // the inner run's MigrationsEnded

        $this->assertTrue($window->isOpen(),
            'The outer run is still applying migrations; its later data migrations must stay silent.');
        $this->assertSame(1, $window->depth());

        $window->close();  // the outer run's MigrationsEnded

        $this->assertFalse($window->isOpen(), 'Once every run has ended the window must close.');
    }

    #[Test]
    public function close_without_open_never_goes_negative(): void
    {
        $window = new MigrationWindow;

        // An unpaired MigrationsEnded — a test firing the event by hand, or a
        // listener registered mid-run.
        $window->close();
        $window->close();

        $this->assertSame(0, $window->depth());
        $this->assertFalse($window->isOpen());

        // The point of the floor: had the depth gone to -2, these two opens
        // would leave it at 0 and a real migrate run would go unguarded.
        $window->open();
        $this->assertTrue($window->isOpen(), 'A real open after stray closes must still open the window.');

        $window->open();
        $window->close();
        $this->assertTrue($window->isOpen());
    }

    #[Test]
    public function reset_closes_every_level_at_once(): void
    {
        $window = new MigrationWindow;

        // A migrate run that threw never fires MigrationsEnded.
        $window->open();
        $window->open();
        $window->reset();

        $this->assertFalse($window->isOpen());
        $this->assertSame(0, $window->depth());
    }
}
