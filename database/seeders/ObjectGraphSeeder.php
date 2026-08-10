<?php

namespace Database\Seeders;

use App\Support\Graph\ObjectBackfiller;
use App\Support\Graph\ObjectRegistryInstaller;
use App\Support\Graph\OrganisationGraphUnifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the object graph installation outside a release.
 *
 * The migrations do this once. This exists for the two cases they cannot
 * cover: a type added to ObjectTypeRegistry after the migration has already
 * run, and a demo database reseeded with new domain rows that need graph
 * identities.
 *
 * Idempotent. Running it twice changes nothing the first run did not already
 * do — except that it will pick up rows created since.
 */
class ObjectGraphSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Installing the system object type registry …');
        (new ObjectRegistryInstaller)->install();

        // The org tree is only rebuilt when it does not exist yet. Re-running
        // the unifier over a graph a human has already reviewed would re-import
        // the same nodes and refill the review queue with pairings that were
        // settled months ago.
        $hasOrgNodes = DB::table('objects')
            ->whereIn('source_model_type', ['entity', 'business_unit', 'business_process'])
            ->exists();

        if ($hasOrgNodes) {
            $this->command?->warn('Organisation nodes already exist — skipping unification. '
                .'Use `php artisan graph:backfill --paths` to repair paths.');
        } else {
            $this->command?->info('Unifying the organisation models …');
            $counts = (new OrganisationGraphUnifier)->run();

            foreach ($counts as $key => $value) {
                $this->command?->line("  {$key}: {$value}");
            }
        }

        $this->command?->info('Backfilling object identities …');
        $synced = (new ObjectBackfiller)->run(source: 'import');

        $this->command?->info('Mirrored '.array_sum($synced).' record(s) into the graph.');
        $this->command?->line('Review the merge queue with: php artisan graph:merge-report --pending');
    }
}
