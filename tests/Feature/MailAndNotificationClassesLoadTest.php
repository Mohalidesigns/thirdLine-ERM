<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every mailable and notification class must be loadable.
 *
 * `public readonly array $queue` on a class using Illuminate\Bus\Queueable is
 * an incompatible property redeclaration: PHP raises an UNCATCHABLE fatal the
 * moment the class is loaded. Nothing in the suite built MyResponsibilitiesDigest,
 * so the fatal reached production, in the 07:30 scheduled run.
 *
 * Because that fatal cannot be caught, each class is loaded in its own PHP
 * process: a failure names the class and the engine's message instead of
 * taking down the whole test run with no hint of which file is at fault.
 * Loading needs only the autoloader, so each child is cheap.
 */
class MailAndNotificationClassesLoadTest extends TestCase
{
    /** @return list<string> */
    private function classes(): array
    {
        $classes = [];

        foreach (['Mail', 'Notifications'] as $directory) {
            $root = app_path($directory);

            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4);
                $classes[] = 'App\\'.str_replace('/', '\\', $relative);
            }
        }

        sort($classes);

        return $classes;
    }

    #[Test]
    public function the_scan_finds_the_mailables_it_is_meant_to_guard(): void
    {
        $classes = $this->classes();

        $this->assertContains(\App\Mail\MyResponsibilitiesDigest::class, $classes);
        $this->assertContains(\App\Mail\Tprm\PortalSignInCode::class, $classes);
        $this->assertContains(\App\Mail\Tprm\ScheduledReport::class, $classes);
    }

    #[Test]
    public function every_mail_and_notification_class_loads_without_a_fatal(): void
    {
        $failures = [];

        foreach ($this->classes() as $class) {
            $process = new Process([
                PHP_BINARY,
                '-r',
                'require $argv[1]; if (! class_exists($argv[2])) { fwrite(STDERR, "class_exists() is false"); exit(2); }',
                base_path('vendor/autoload.php'),
                $class,
            ]);
            $process->run();

            if (! $process->isSuccessful()) {
                $failures[] = $class.': '.trim($process->getOutput().$process->getErrorOutput());
            }
        }

        $this->assertSame([], $failures, "These classes cannot be loaded:\n".implode("\n", $failures));
    }
}
