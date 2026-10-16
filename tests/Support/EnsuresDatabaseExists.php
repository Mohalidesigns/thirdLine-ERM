<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * For a test that touches the connection without RefreshDatabase.
 *
 * Such a test runs first on a fresh checkout or a new CI database that nothing
 * has created or migrated yet, and dies with 1049 "Unknown database". Create
 * the schema (empty) if it is missing; a database that exists is left alone.
 */
trait EnsuresDatabaseExists
{
    protected function ensureDatabaseExists(): void
    {
        try {
            DB::connection()->getPdo();

            return;
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), '1049')) {
                throw $e;
            }
        }

        $c = config('database.connections.'.config('database.default'));
        $database = str_replace('`', '``', (string) $c['database']);

        $pdo = new \PDO(
            "mysql:host={$c['host']};port={$c['port']}",
            (string) $c['username'],
            (string) $c['password'],
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");

        DB::purge();
    }
}
