<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every class under app/ must be loadable.
 *
 * `public readonly array $queue` on a class using Illuminate\Bus\Queueable is
 * an incompatible property redeclaration: PHP raises an UNCATCHABLE fatal the
 * moment the class is loaded. Nothing in the suite built MyResponsibilitiesDigest,
 * so the fatal reached production, in the 07:30 scheduled run. Jobs, listeners
 * and notifications use the same traits (Queueable, InteractsWithQueue,
 * SerializesModels, Dispatchable), so the guard covers all of app/.
 *
 * Because that fatal cannot be caught, loading happens in a CHILD process.
 * First ALL classes in ONE child (a few seconds): if it exits clean, done. Only
 * when it fails does the search for the culprit begin, so that the failure
 * names the class and the engine's message instead of taking down the whole
 * test run with no hint of which file is at fault. Loading needs only the
 * autoloader.
 */
class MailAndNotificationClassesLoadTest extends TestCase
{
    /** @return list<string> */
    private function classes(?array $directories = null): array
    {
        $classes = [];

        $roots = $directories === null
            ? [app_path()]
            : array_map(fn (string $directory) => app_path($directory), $directories);

        foreach ($roots as $root) {
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

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * Child-process loader: any class-like (class, interface, trait, enum)
     * counts. The class about to be loaded is written to a progress file FIRST,
     * so that when PHP dies with an uncatchable fatal the parent knows which
     * class it died on.
     */
    private const LOADER = <<<'PHP'
        require $argv[1];
        $missing = [];
        foreach (array_filter(explode("\n", file_get_contents($argv[2]))) as $name) {
            file_put_contents($argv[3], $name);
            if (! (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name))) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            fwrite(STDERR, implode("\n", $missing));
            exit(2);
        }
        PHP;

    private const EXIT_UNDECLARED = 2;

    /**
     * Load $classes in ONE child process.
     *
     * @return array{status: 'clean'|'undeclared'|'fatal', output: string, culprit: ?string}
     */
    private function loadTogether(array $classes, ?string $autoload = null): array
    {
        $list = tempnam(sys_get_temp_dir(), 'classload');
        $progress = tempnam(sys_get_temp_dir(), 'classprogress');
        file_put_contents($list, implode("\n", $classes));

        try {
            $process = new Process([PHP_BINARY, '-r', self::LOADER, $autoload ?? base_path('vendor/autoload.php'), $list, $progress]);
            $process->setTimeout(120);
            $process->run();
            $culprit = trim((string) file_get_contents($progress)) ?: null;
        } finally {
            @unlink($list);
            @unlink($progress);
        }

        $output = trim($process->getOutput().$process->getErrorOutput());

        return match (true) {
            $process->isSuccessful() => ['status' => 'clean', 'output' => '', 'culprit' => null],
            $process->getExitCode() === self::EXIT_UNDECLARED => ['status' => 'undeclared', 'output' => $output, 'culprit' => null],
            default => ['status' => 'fatal', 'output' => $output, 'culprit' => $culprit],
        };
    }

    /**
     * Every class that fails to load, as "Class: message".
     *
     * ONE process loads everything (a few seconds). Only if that fails does
     * the search for WHICH class begin, and it must stay cheap on the failure
     * path too: a PHP start-up with the autoloader costs a fraction of a
     * second here, so one process per class is minutes for ~1500 classes. The
     * child therefore records the class it is about to load; on a fatal the
     * parent names that class, then resumes with the classes after it. That is
     * one extra process per broken class, not one per class in the app.
     *
     * @param  string|null  $autoload  an autoload file other than the app's, for the fixture test
     * @return list<string>
     */
    private function failuresFor(array $classes, ?string $autoload = null): array
    {
        $failures = [];

        while ($classes !== []) {
            $result = $this->loadTogether($classes, $autoload);

            if ($result['status'] === 'clean') {
                break;
            }

            if ($result['status'] === 'undeclared') {
                foreach (array_filter(explode("\n", $result['output'])) as $name) {
                    $failures[] = $name.': class_exists() is false; the file does not declare it';
                }

                break;
            }

            $position = $result['culprit'] === null ? false : array_search($result['culprit'], $classes, true);

            if ($position === false) {
                // The child died before naming a class. Say so, rather than loop.
                $failures[] = '(unknown class): '.$result['output'];

                break;
            }

            $failures[] = $classes[$position].': '.$result['output'];
            $classes = array_slice($classes, $position + 1);
        }

        return $failures;
    }

    #[Test]
    public function the_scan_finds_the_mailables_it_is_meant_to_guard(): void
    {
        $classes = $this->classes();

        $this->assertContains(\App\Mail\MyResponsibilitiesDigest::class, $classes);
        $this->assertContains(\App\Mail\Tprm\PortalSignInCode::class, $classes);
        $this->assertContains(\App\Mail\Tprm\ScheduledReport::class, $classes);

        // Jobs and listeners use the same queue traits and are guarded too.
        $this->assertContains(\App\Jobs\DeliverWebhookJob::class, $classes);
        $this->assertContains(\App\Listeners\RecalculateResidualRisk::class, $classes);
        $this->assertContains(\App\Jobs\Concerns\TracksJobProgress::class, $classes);
    }

    #[Test]
    public function every_mail_and_notification_class_loads_without_a_fatal(): void
    {
        $failures = $this->failuresFor($this->classes(['Mail', 'Notifications']));

        $this->assertSame([], $failures, "These classes cannot be loaded:\n".implode("\n", $failures));
    }

    #[Test]
    public function every_class_under_app_loads_without_a_fatal(): void
    {
        $failures = $this->failuresFor($this->classes());

        $this->assertSame([], $failures, "These classes cannot be loaded:\n".implode("\n", $failures));
    }

    #[Test]
    public function a_fatal_is_named_and_the_search_resumes_with_the_classes_after_it(): void
    {
        // A fixture world: its own autoload file and three classes, in this order.
        //   FatalOne  - a Queueable `$queue` clash (uncatchable fatal on load)
        //   Good      - loads fine and leaves a marker file when it is loaded
        //   FatalTwo  - another fatal
        // Without the progress write the parent cannot name FatalOne, and
        // without the resume Good is never checked and FatalTwo never found.
        $dir = sys_get_temp_dir().'/classload-fixture-'.bin2hex(random_bytes(6));
        mkdir($dir);

        $fatal = static fn (string $name) => "<?php\nnamespace Fixture;\nclass {$name}\n{\n    use \\Illuminate\\Bus\\Queueable;\n\n    public function __construct(public readonly array \$queue = []) {}\n}\n";

        file_put_contents($dir.'/FatalOne.php', $fatal('FatalOne'));
        file_put_contents($dir.'/FatalTwo.php', $fatal('FatalTwo'));
        file_put_contents($dir.'/Good.php', "<?php\nnamespace Fixture;\nfile_put_contents(__DIR__.'/good.loaded', 'yes');\nclass Good {}\n");
        file_put_contents($dir.'/autoload.php', "<?php\nrequire ".var_export(base_path('vendor/autoload.php'), true).";\n"
            ."spl_autoload_register(function (\$class) {\n"
            ."    \$file = __DIR__.'/'.substr(\$class, strlen('Fixture\\\\')).'.php';\n"
            ."    if (str_starts_with(\$class, 'Fixture\\\\') && is_file(\$file)) { require \$file; }\n"
            ."});\n");

        try {
            $failures = $this->failuresFor(
                ['Fixture\\FatalOne', 'Fixture\\Good', 'Fixture\\FatalTwo'],
                $dir.'/autoload.php',
            );

            $named = array_map(fn (string $failure) => strtok($failure, ':'), $failures);

            $this->assertSame(['Fixture\\FatalOne', 'Fixture\\FatalTwo'], $named, implode("\n", $failures));
            $this->assertStringContainsString('define the same property ($queue)', $failures[0]);
            $this->assertStringContainsString('define the same property ($queue)', $failures[1]);
            $this->assertFileExists($dir.'/good.loaded', 'the class after the first fatal was never checked');
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }

        $this->assertDirectoryDoesNotExist($dir);
    }
}
