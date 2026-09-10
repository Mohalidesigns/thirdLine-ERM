<?php

namespace App\Console\Commands;

use App\Jobs\RebuildHierarchyPaths;
use App\Support\Graph\ObjectBackfiller;
use App\Support\Graph\ObjectRegistryInstaller;
use Illuminate\Console\Command;

/**
 * Repair or extend the object graph without a migration.
 *
 * Three things need to be re-runnable outside a release: re-seeding the type
 * registry after a type is added to ObjectTypeRegistry, giving graph identities
 * to rows that somehow have none, and re-materialising the paths that
 * node-scoped authorization reads.
 */
class GraphBackfill extends Command
{
    protected $signature = 'graph:backfill
                            {--registry : re-install the system type registry first}
                            {--paths : rebuild hierarchy paths afterwards}
                            {--model=* : limit to these model classes}';

    protected $description = 'Backfill object graph identities for existing records.';

    public function handle(): int
    {
        if ($this->option('registry')) {
            $this->info('Installing the system type registry …');
            (new ObjectRegistryInstaller)->install();
        }

        $only = $this->option('model');
        $only = $only === [] ? null : $only;

        $this->info('Backfilling object identities …');
        $synced = (new ObjectBackfiller)->run($only, source: 'job');

        $this->table(
            ['Model', 'Rows synced'],
            collect($synced)->map(fn (int $count, string $class) => [class_basename($class), $count])->values()->all()
        );

        if ($this->option('paths')) {
            $this->info('Rebuilding hierarchy paths …');
            (new RebuildHierarchyPaths)->handle();
        }

        $this->info('Done. '.array_sum($synced).' record(s) mirrored into the graph.');

        return self::SUCCESS;
    }
}
