<?php

namespace App\Grids;

use InvalidArgumentException;

/**
 * Name → definition map. The Livewire component receives the NAME (a string
 * survives Livewire hydration; an object would not) and resolves it here on
 * every request.
 */
class GridRegistry
{
    /** @var array<string, class-string<GridDefinition>> */
    protected static array $grids = [
        'controls' => Definitions\ControlsGrid::class,
    ];

    public static function resolve(string $name): GridDefinition
    {
        $class = static::$grids[$name] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown data grid [{$name}].");
        }

        return app($class);
    }

    /** @param class-string<GridDefinition> $class */
    public static function register(string $name, string $class): void
    {
        static::$grids[$name] = $class;
    }
}
