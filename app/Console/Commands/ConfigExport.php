<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Configuration\ConfigurationExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * WP-05 TASK 4 — config:export
 */
class ConfigExport extends Command
{
    protected $signature = 'config:export
        {--organization= : Organization id. Required unless exactly one exists.}
        {--code=baseline : Bundle code; re-exporting the same code makes a new version}
        {--name= : Human name for the bundle}
        {--description= : What this bundle is for}
        {--file= : Also write the payload to this path as JSON}';

    protected $description = 'Export an organisation\'s configuration as a versioned bundle';

    public function handle(ConfigurationExporter $exporter): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        TenantContext::set($organization->id);

        $code = (string) $this->option('code');

        $bundle = $exporter->export(
            code: $code,
            name: (string) ($this->option('name') ?: ucfirst($code).' configuration'),
            description: $this->option('description'),
            organizationId: $organization->id,
        );

        $this->info("Exported {$bundle->label()} for {$organization->name}.");
        $this->line("  checksum  {$bundle->checksum}");
        $this->line('  from      '.$bundle->source_environment);
        $this->newLine();

        $rows = [];

        foreach ($bundle->sectionCounts() as $section => $count) {
            $rows[] = [$section, $count];
        }

        $this->table(['Section', 'Rows'], $rows);
        $this->line('  '.$bundle->totalRows().' row(s) total.');

        if ($path = $this->option('file')) {
            file_put_contents($path, json_encode([
                'code' => $bundle->code,
                'name' => $bundle->name,
                'version' => $bundle->version,
                'checksum' => $bundle->checksum,
                'payload' => $bundle->payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $this->info("Written to {$path}.");
        }

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $id = $this->option('organization');

        if ($id !== null) {
            $organization = Organization::find($id);

            if ($organization === null) {
                $this->error("No organization with id {$id}.");
            }

            return $organization;
        }

        $organizations = Organization::all();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        // Guessing which tenant to export is exactly the mistake that produces
        // a bundle applied to the wrong organisation an hour later.
        $this->error('This installation has '.$organizations->count().' organizations. Pass --organization=<id>.');

        foreach ($organizations as $organization) {
            $this->line("  {$organization->id}  {$organization->name}");
        }

        return null;
    }
}
