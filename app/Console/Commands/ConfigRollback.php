<?php

namespace App\Console\Commands;

use App\Models\ConfigBundleApplication;
use App\Services\Configuration\ConfigurationImporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * WP-05 TASK 4 — config:rollback
 *
 * Restores the configuration to the snapshot taken immediately before an
 * apply. Not an undo log: the snapshot is the actual prior state, so a
 * rollback cannot be wrong about what a forward operation touched.
 */
class ConfigRollback extends Command
{
    protected $signature = 'config:rollback
        {application? : The application log id to roll back. Omit to list what can be rolled back.}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the configuration to the snapshot taken before an apply';

    public function handle(ConfigurationImporter $importer): int
    {
        $id = $this->argument('application');

        if ($id === null) {
            return $this->listRollbackable();
        }

        $application = ConfigBundleApplication::withoutGlobalScopes()->find((int) $id);

        if ($application === null) {
            $this->error("No application log entry with id {$id}.");

            return self::FAILURE;
        }

        if (! $application->isRollbackable()) {
            $this->error($application->rolled_back_by_application_id !== null
                ? "Log entry #{$application->id} has already been rolled back by #{$application->rolled_back_by_application_id}."
                : "Log entry #{$application->id} cannot be rolled back — only a successful apply with a snapshot can be.");

            return self::FAILURE;
        }

        TenantContext::set($application->organization_id);

        $this->warn("This restores the configuration to how it was before log entry #{$application->id} "
            .'('.$application->applied_at->format('d M Y H:i').').');
        $this->line('  That apply made: '.$application->summary());

        if (! $this->option('force') && ! $this->confirm('Roll back?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $rollback = $importer->rollback($application);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Rolled back. Log entry #{$rollback->id} records the restore: ".$rollback->summary().'.');

        return self::SUCCESS;
    }

    private function listRollbackable(): int
    {
        $applications = ConfigBundleApplication::withoutGlobalScopes()
            ->where('mode', 'apply')
            ->where('outcome', 'ok')
            ->whereNotNull('snapshot_bundle_id')
            ->whereNull('rolled_back_by_application_id')
            ->with('bundle')
            ->latest('applied_at')
            ->limit(20)
            ->get();

        if ($applications->isEmpty()) {
            $this->info('Nothing to roll back.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Applied', 'Bundle', 'Org', 'Changes'],
            $applications->map(fn (ConfigBundleApplication $a) => [
                $a->id,
                $a->applied_at->format('d M Y H:i'),
                $a->bundle?->label() ?? '—',
                $a->organization_id,
                $a->summary(),
            ])->all()
        );

        $this->line('Roll one back with:  php artisan config:rollback <#>');

        return self::SUCCESS;
    }
}
