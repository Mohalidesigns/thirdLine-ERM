<?php

namespace App\Support\Migration;

use Illuminate\Support\Facades\Route;

/**
 * Which routes render through Inertia today.
 *
 * Until Phase 6 the application has two renderers, and a link has to know
 * which one is on the other end: an Inertia <Link> to a Blade page gets a
 * non-Inertia HTML response and shows it in an error modal, and a Blade
 * `wire:navigate` to an Inertia page swaps in a document whose React bundle
 * never boots. Both sidebars (NavPresenter for React, sidebar.blade.php for
 * Blade) consult this list so that a link crossing the boundary is a plain
 * full-page navigation. Grows by one entry per ported route, and shrinks to
 * nothing worth keeping when the Blade side is gone.
 */
final class Ported
{
    /** @var list<string> */
    public const ROUTES = [
        // Phase 0
        'my.index',
        'admin.license',
        // Phase 1
        'login',
        'password.request',
        'password.reset',
        'mfa.setup',
        'mfa.verify',
        'profile.edit',
        'notifications.index',
        'search.index',
    ];

    public static function isRoute(string $name): bool
    {
        return in_array($name, self::ROUTES, true);
    }

    /**
     * Is this URL path served by an Inertia page? Matches parameterless
     * routes only, which is every entry the Blade sidebar links to.
     */
    public static function isPath(string $path): bool
    {
        $path = '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');

        return in_array($path, self::paths(), true);
    }

    /**
     * The `wire:navigate` attribute for a Blade link, or nothing when the
     * destination is an Inertia page.
     */
    public static function navigateAttribute(string $path): string
    {
        return self::isPath($path) ? '' : 'wire:navigate';
    }

    /**
     * @return list<string>
     */
    private static function paths(): array
    {
        static $paths = null;

        if ($paths !== null) {
            return $paths;
        }

        $paths = [];

        foreach (self::ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route !== null && ! str_contains($route->uri(), '{')) {
                $paths[] = '/'.trim($route->uri(), '/');
            }
        }

        return $paths;
    }
}
