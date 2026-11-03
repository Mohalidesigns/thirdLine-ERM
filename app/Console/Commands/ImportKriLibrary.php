<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Kri\KriLibraryImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Loads a departmental KRI library into ONE tenant (KriLibraryImporter).
 *
 * The organisation is required, never defaulted to "all": creating three
 * hundred KRIs and a set of departments is a change to a bank's risk framework,
 * so it is run explicitly for the bank it belongs to - the rule
 * `bcms:kri:adopt` states for the same reason. `--dry-run` does the whole
 * import inside a transaction and rolls it back, so its report is exactly what
 * a real run would do. Safe to re-run: existing KRIs are never overwritten.
 */
class ImportKriLibrary extends Command
{
    protected $signature = 'kri:import-library
        {--organization= : The organisation id to load the library into (required)}
        {--file= : Library JSON, default database/data/kri-library/bank-kri-library.json}
        {--map=* : Send a library department to an existing unit, LIBCODE=UNITCODE (e.g. LEG=BU-LG); repeatable}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Load a departmental KRI library: departments as business units, each KRI with its bands.';

    public function handle(KriLibraryImporter $importer): int
    {
        $organization = $this->option('organization') === null
            ? null
            : Organization::query()->find($this->option('organization'));

        if ($organization === null) {
            $this->error('Pass --organization=<id> of an existing organisation.');

            return self::FAILURE;
        }

        $path = $this->option('file') ?: database_path('data/kri-library/bank-kri-library.json');
        $library = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($library)) {
            $this->error("Could not read a KRI library from {$path}.");

            return self::FAILURE;
        }

        $map = [];

        foreach ((array) $this->option('map') as $pair) {
            if (! preg_match('/^\s*([^=\s]+)\s*=\s*([^=\s]+)\s*$/', (string) $pair, $m)) {
                $this->error("--map takes LIBCODE=UNITCODE, got: {$pair}");

                return self::FAILURE;
            }

            $map[$m[1]] = $m[2];
        }

        TenantContext::set($organization->id);

        try {
            $result = $importer->import($library, null, (bool) $this->option('dry-run'), $map);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            TenantContext::clear();
        }

        $this->line(($this->option('dry-run') ? '[dry run - nothing written] ' : '').$organization->name);
        $this->line(sprintf(
            'Departments: %d created, %d matched to existing units, %d refused.',
            count($result['units_created']), count($result['units_matched']), count($result['units_refused']),
        ));

        foreach ($result['units_matched'] as $line) {
            $this->line("  matched {$line}");
        }

        foreach ($result['units_refused'] as $line) {
            $this->warn("  refused {$line}");
        }

        $this->line(sprintf(
            'KRIs: %d created, %d already present (left unchanged), %d skipped for a refused department.',
            $result['kris_created'], $result['kris_existing'], count($result['kris_skipped']),
        ));
        if ($result['kris_deleted'] !== []) {
            $this->warn('Deleted in this organisation, so not recreated: '.implode(', ', $result['kris_deleted']).'.');
        }

        $this->line("Owners assigned: {$result['owners_assigned']}.");

        if ($result['owners_unresolved'] !== []) {
            $this->warn('No single active user holds these job titles, so their KRIs have no owner yet:');

            foreach ($result['owners_unresolved'] as $title => $count) {
                $this->warn("  {$title} ({$count} KRIs)");
            }

            $this->line('Set the job title on the owner\'s user record and re-run to assign them.');
        }

        return self::SUCCESS;
    }
}
