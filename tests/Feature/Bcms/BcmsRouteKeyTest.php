<?php

namespace Tests\Feature\Bcms;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every BCMS aggregate root is routed by UUID, and every screen has to know it.
 *
 * `HasBcmsUuid::getRouteKeyName()` returns `uuid` — deliberately, so that a
 * customer's URLs do not enumerate their estate and a link in an email does not
 * leak how many plans a bank has. The cost is that `route('bcms.plans.show', 12)`
 * builds `/plans/12`, which resolves to nothing, and **nothing fails loudly**:
 * the server 404s, Inertia swallows it, and the user sees a link that does not
 * work. It is invisible to a feature test that passes the model, because
 * `route($name, $model)` uses the route key and is always right.
 *
 * So this greps the pages instead. For every `tryRoute('bcms.…', x.id)` in the
 * BCMS screens it asks whether that route's first parameter binds a uuid-keyed
 * model, and fails if it does. The fix is always the same: pass `x.uuid`, and
 * make sure the presenter sends it.
 *
 * A PARAMETER THAT IS NOT A MODEL IS FINE. `bcms.dependencies.impact-of` takes
 * a raw `{type}/{id}` pair and a numeric id there is correct; so is a nested
 * `{section}` or `{dependency}`, whose models carry no uuid.
 */
class BcmsRouteKeyTest extends TestCase
{
    #[Test]
    public function no_bcms_screen_addresses_a_uuid_routed_model_by_its_numeric_id(): void
    {
        $uuidParameters = $this->uuidRoutedParameters();

        $this->assertNotEmpty($uuidParameters, 'No uuid-routed BCMS routes were found — the guard would pass vacuously.');

        $offenders = [];

        foreach ($this->screenFiles() as $file) {
            $relative = Str::after($file, base_path().'/');
            $lines = file($file) ?: [];

            foreach ($lines as $index => $line) {
                if (! preg_match_all("/tryRoute\(\s*'(bcms\.[a-z0-9._-]+)'\s*,\s*(\[)?\s*([A-Za-z0-9_.\[\]]+)/", $line, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    [, $name, , $firstArgument] = $match;

                    if (! ($uuidParameters[$name] ?? false)) {
                        continue;
                    }

                    // The first route parameter is the one a bare argument, or
                    // the head of an array, binds to.
                    if (! str_ends_with($firstArgument, '.id') && ! str_ends_with($firstArgument, '_id')) {
                        continue;
                    }

                    $offenders[] = sprintf('%s:%d — %s', $relative, $index + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A BCMS screen builds a URL from a numeric id for a route whose model is keyed by uuid.\n"
            ."The link 404s and Inertia swallows it, so nothing looks broken until somebody clicks.\n"
            ."Pass the uuid instead, and check the presenter sends one.\n\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * Route name => whether its FIRST parameter binds a uuid-keyed model.
     *
     * @return array<string, bool>
     */
    private function uuidRoutedParameters(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'bcms.')) {
                continue;
            }

            $parameters = $route->parameterNames();

            if ($parameters === []) {
                continue;
            }

            $map[$name] = $this->bindsUuidModel($route, $parameters[0]);
        }

        return $map;
    }

    private function bindsUuidModel(RoutingRoute $route, string $parameter): bool
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return false;
        }

        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $argument) {
            if ($argument->getName() !== $parameter) {
                continue;
            }

            $type = $argument->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return false;
            }

            $model = $type->getName();

            if (! class_exists($model) || ! is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
                return false;
            }

            return (new $model)->getRouteKeyName() !== (new $model)->getKeyName();
        }

        return false;
    }

    /** @return list<string> */
    private function screenFiles(): array
    {
        $files = [];

        foreach ([resource_path('js/Pages/Bcms'), resource_path('js/Components/Bcms')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'jsx') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
