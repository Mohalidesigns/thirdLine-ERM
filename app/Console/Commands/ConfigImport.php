<?php

namespace App\Console\Commands;

use App\Models\ConfigBundle;
use App\Models\Organization;
use App\Services\Configuration\ConfigurationExporter;
use App\Services\Configuration\ConfigurationImporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * WP-05 TASK 4 — config:import
 *
 * DRY RUN IS THE DEFAULT. Nothing is written unless --apply is passed. The
 * flag in the work package's brief is `--dry-run`; it is accepted, and it is
 * also the behaviour you get without it, because a tool whose destructive mode
 * is the default is a tool that eventually destroys something.
 */
class ConfigImport extends Command
{
    protected $signature = 'config:import
        {source : A bundle id, or a path to a JSON file produced by config:export}
        {--organization= : Target organization id. Required unless exactly one exists.}
        {--dry-run : Explicit no-op; this is already the default}
        {--apply : Actually write the changes}
        {--force : Apply even when there are conflicting changes, taking the bundle\'s version}
        {--prune : Also remove configuration the bundle does not contain}';

    protected $description = 'Import a configuration bundle. Produces a diff and writes nothing unless --apply';

    public function handle(ConfigurationImporter $importer, ConfigurationExporter $exporter): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        TenantContext::set($organization->id);

        $bundle = $this->resolveBundle($organization->id, $exporter);

        if ($bundle === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $result = $importer->plan($bundle->payload, $organization->id);
            $this->renderDiff($result['diff']);

            $this->newLine();
            $this->comment('Dry run — nothing was written. Re-run with --apply to write these changes.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->apply(
                $bundle,
                $organization->id,
                force: (bool) $this->option('force'),
                prune: (bool) $this->option('prune'),
            );
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->renderDiff($result['diff']);
        $this->newLine();

        $application = $result['application'];

        if ($application->outcome === 'no_changes') {
            $this->info('Nothing to apply — this configuration is already in place.');

            return self::SUCCESS;
        }

        $this->info("Applied. Log entry #{$application->id}.");
        $this->line("  Roll back with:  php artisan config:rollback {$application->id}");

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $diff
     */
    private function renderDiff(array $diff): void
    {
        if ($diff['is_empty']) {
            $this->info('No differences — the target already matches this bundle.');

            return;
        }

        foreach ($diff['sections'] as $section => $changes) {
            $this->newLine();
            $this->line("<options=bold>{$section}</>");

            foreach ($changes['added'] as $entry) {
                $this->line("  <fg=green>+ {$entry['key']}</>");
            }

            foreach ($changes['changed'] as $entry) {
                $this->line("  <fg=yellow>~ {$entry['key']}</>");

                foreach ($entry['fields'] as $field => $change) {
                    $this->line(sprintf(
                        '      %s: %s → %s',
                        $field,
                        $this->short($change['from']),
                        $this->short($change['to']),
                    ));
                }
            }

            foreach ($changes['removed'] as $entry) {
                $this->line("  <fg=red>- {$entry['key']}</>"
                    .($this->option('prune') ? '' : '  <fg=gray>(kept; pass --prune to remove)</>'));
            }

            foreach ($changes['conflicting'] as $entry) {
                $this->line("  <fg=magenta>! {$entry['key']}  CONFLICT</>");

                foreach ($entry['fields'] as $field => $sides) {
                    $this->line(sprintf(
                        '      %s: base %s | here %s | bundle %s',
                        $field,
                        $this->short($sides['base']),
                        $this->short($sides['ours']),
                        $this->short($sides['theirs']),
                    ));
                }
            }
        }

        $totals = $diff['totals'];

        $this->newLine();
        $this->table(
            ['Added', 'Changed', 'Removed', 'Conflicting'],
            [[$totals['added'], $totals['changed'], $totals['removed'], $totals['conflicting']]],
        );

        if ($totals['conflicting'] > 0) {
            $this->warn('Conflicts: these were changed both here and in the bundle since the last apply. '
                .'Applying with --force takes the bundle\'s version and discards the local change.');
        }
    }

    private function short(mixed $value): string
    {
        if ($value === null) {
            return '<fg=gray>null</>';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $text = is_scalar($value) ? (string) $value : json_encode($value);

        return strlen($text) > 60 ? substr($text, 0, 57).'…' : $text;
    }

    private function resolveBundle(int $organizationId, ConfigurationExporter $exporter): ?ConfigBundle
    {
        $source = (string) $this->argument('source');

        if (is_numeric($source)) {
            $bundle = ConfigBundle::withoutGlobalScopes()->find((int) $source);

            if ($bundle === null) {
                $this->error("No bundle with id {$source}.");
            }

            return $bundle;
        }

        if (! is_file($source)) {
            $this->error("No such file: {$source}.");

            return null;
        }

        $decoded = json_decode((string) file_get_contents($source), true);

        if (! is_array($decoded) || ! isset($decoded['payload'])) {
            $this->error('That file is not a bundle exported by config:export.');

            return null;
        }

        // Not persisted: an unsaved model carries the payload through to the
        // importer without claiming a version number for a bundle that may
        // turn out to be unapplyable.
        $bundle = new ConfigBundle([
            'organization_id' => $organizationId,
            'code' => $decoded['code'] ?? 'imported',
            'name' => $decoded['name'] ?? 'Imported bundle',
            'version' => $decoded['version'] ?? 1,
            'payload' => $decoded['payload'],
            'checksum' => $decoded['checksum'] ?? ConfigurationExporter::checksum($decoded['payload']),
            'source_environment' => $decoded['payload']['exported_from'] ?? null,
        ]);

        if ($this->option('apply')) {
            // Applying needs a persisted bundle so the log can point at it.
            $bundle->exported_at = now();
            $bundle->version = 1 + (int) ConfigBundle::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('code', $bundle->code)
                ->max('version');
            $bundle->save();
        }

        return $bundle;
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

        $this->error('This installation has '.$organizations->count().' organizations. Pass --organization=<id>.');

        return null;
    }
}
