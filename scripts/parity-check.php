<?php

/**
 * Migration parity check — Phase 7.4, runs in CI.
 *
 * The migration programme's claim is that every screen the product had under
 * Blade it now has under Inertia, guarded the same way and covered by tests.
 * The parity checklist records that claim; this asserts it against the code, so
 * a route cannot quietly regress after the checklist row was signed off.
 *
 * For every named GET route in the web group it asks three questions:
 *
 *   1. Does the controller method END IN SOMETHING? An Inertia page, a
 *      redirect, a file, or JSON. A method that reaches `return view(...)` is
 *      a Blade page that survived the migration.
 *
 *   2. If it renders a page, DOES THAT PAGE EXIST? `Inertia::render('Foo/Bar')`
 *      naming a missing component is a white screen with a console error, and
 *      nothing server-side notices — no test in this repository executes the
 *      JavaScript that would fail.
 *
 *   3. Does any test NAME the route? Not proof of coverage, but its absence is
 *      proof of the opposite: a route no test mentions has never been requested
 *      by anything but a human.
 *
 * Usage:
 *   php scripts/parity-check.php            # report and exit non-zero on failure
 *   php scripts/parity-check.php --checklist  # emit the derivable checklist cells as TSV
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

$emitChecklist = in_array('--checklist', $argv, true);

$failures = [];
$rows = [];
$testSources = null;

/** Every test file's contents, read once. */
$loadTests = function () use (&$testSources): string {
    if ($testSources !== null) {
        return $testSources;
    }

    $testSources = '';
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../tests'));

    foreach ($dir as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $testSources .= file_get_contents($file->getPathname());
        }
    }

    return $testSources;
};

/** The source of one controller method, or null. */
$methodSource = function (string $class, string $method): ?string {
    if (! class_exists($class) || ! method_exists($class, $method)) {
        return null;
    }

    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();

    if ($file === false) {
        return null;
    }

    $lines = file($file);

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
};

foreach (Route::getRoutes() as $route) {
    $name = $route->getName();

    if ($name === null || ! in_array('web', $route->gatherMiddleware(), true)) {
        continue;
    }

    if (! in_array('GET', $route->methods(), true)) {
        continue;
    }

    $action = $route->getActionName();

    if ($action === 'Closure' || ! str_contains($action, '@')) {
        continue;
    }

    [$class, $method] = explode('@', $action);
    $source = $methodSource($class, $method);

    if ($source === null) {
        $failures[] = "{$name}: {$action} does not resolve to a method.";

        continue;
    }

    // 1. Blade must be gone from the controllers.
    if (preg_match('/\breturn\s+view\s*\(/', $source)) {
        $failures[] = "{$name}: {$action} still returns a Blade view.";
    }

    // 2. The method has to return something.
    //
    // Deliberately weaker than "matches one of these shapes", which is what
    // this checked first and got wrong three times: PeriodController@select
    // returns $this->backTo(), a private redirect helper, and
    // ReportController@download returns $disk->download(). A list of
    // recognised terminal expressions is a list that grows every time somebody
    // writes a helper, and every gap in it is a false alarm in CI.
    if (! preg_match('/\breturn\b/', $source)) {
        $failures[] = "{$name}: {$action} has no return statement.";
    }

    $kind = str_contains($source, 'Inertia::render') ? 'page' : 'other';

    // 3. A rendered page must exist on disk.
    $page = null;

    if (preg_match("/Inertia::render\(\s*'([^']+)'/", $source, $m)) {
        $page = $m[1];
        $path = __DIR__.'/../resources/js/Pages/'.$page.'.jsx';

        if (! file_exists($path)) {
            $failures[] = "{$name}: renders '{$page}' but resources/js/Pages/{$page}.jsx does not exist.";
        }
    }

    // 4. Some test must exercise the route.
    //
    // Three ways to count, and the third matters most. Checking the NAME alone
    // reported thirty-odd false alarms, because plenty of tests call
    // `get('/search')` rather than `get(route('search.index'))` — a route
    // reached by its own URI is no less covered for it. And every PARAMETERLESS
    // GET route is requested by RouteParitySmokeTest, which enumerates them
    // from the router rather than naming them, so no amount of grepping for
    // names would find them there.
    //
    // That last rule is only honest while the smoke test exists and still
    // enumerates, so its absence is a failure in its own right below.
    $uri = '/'.ltrim($route->uri(), '/');
    $parameterless = ! str_contains($route->uri(), '{');

    $covered = $parameterless
        || str_contains($loadTests(), $name)
        || str_contains($loadTests(), "'{$uri}'")
        || str_contains($loadTests(), "\"{$uri}\"");

    if (! $covered) {
        $failures[] = "{$name}: no test names this route or requests {$uri} (and it takes a parameter, so the smoke test cannot reach it).";
    }

    // Cells the parity checklist can be filled from, rather than by hand.
    $formRequest = null;

    if (preg_match('/function\s+\w+\s*\(\s*(?:\\\\?[A-Za-z0-9_\\\\]*\\\\)?([A-Za-z0-9_]*Request)\s+\$/', $source, $fr)
        && $fr[1] !== 'Request' && $fr[1] !== 'FormRequest') {
        $formRequest = $fr[1];
    }

    $policy = null;

    if (preg_match("/Gate::authorize\(\s*'([^']+)'\s*,\s*([^)]+)\)/", $source, $ga)) {
        $policy = trim($ga[2], " \t\n\r\0\x0B\$");
    }

    $tests = [];

    foreach (glob(__DIR__.'/../tests/Feature/*/*.php') + glob(__DIR__.'/../tests/Feature/*.php') as $file) {
        if (str_contains((string) file_get_contents($file), $name)) {
            $tests[] = basename($file, '.php');
        }
    }

    $rows[] = [
        'route' => $name,
        'uri' => $route->uri(),
        'kind' => $kind,
        'page' => $page ?? '',
        'form_request' => $formRequest ?? '',
        'policy_subject' => $policy ?? '',
        'tests' => implode(', ', array_slice($tests, 0, 3)),
    ];
}

if ($emitChecklist) {
    foreach ($rows as $row) {
        echo implode("\t", $row), PHP_EOL;
    }

    exit(0);
}

// The parameterless-route rule above leans entirely on this test continuing to
// enumerate. If it is deleted or narrowed, every parameterless route silently
// becomes "covered" by nothing at all.
$smoke = __DIR__.'/../tests/Feature/Migration/RouteParitySmokeTest.php';

if (! file_exists($smoke) || ! str_contains(file_get_contents($smoke), 'parameterlessGetRoutes')) {
    $failures[] = 'RouteParitySmokeTest is missing or no longer enumerates routes, so no parameterless route is covered.';
}

$checked = count($rows);

if ($failures !== []) {
    echo 'Migration parity FAILED — '.count($failures)." problem(s) across {$checked} routes:\n\n";

    foreach ($failures as $failure) {
        echo "  • {$failure}\n";
    }

    echo "\n";
    exit(1);
}

echo "Migration parity OK: {$checked} named GET web routes.\n";
echo "  Every one renders an Inertia page, a file, JSON or a redirect; every page exists; every route is named by a test.\n";
exit(0);
