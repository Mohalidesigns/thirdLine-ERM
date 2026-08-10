<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Console\Command;

/**
 * Install the shipped workflow definitions for an organization.
 *
 * Needed beyond the seeding migration because organizations are created after
 * deployment, and a new tenant with no approval processes would silently fall
 * back to direct status changes with nobody reviewing anything.
 */
class ProvisionWorkflows extends Command
{
    protected $signature = 'workflow:provision
                            {--organization= : One organization id; all of them when omitted}
                            {--force : Reinstall even where a definition with the code already exists}';

    protected $description = 'Install the shipped workflow definitions for one or every organization';

    public function handle(WorkflowPublisher $publisher): int
    {
        $organizations = Organization::query()->withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q) => $q->whereKey($this->option('organization')))
            ->orderBy('id')
            ->get();

        if ($organizations->isEmpty()) {
            $this->error('No matching organization.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            $installed = $publisher->provision($organization->id, (bool) $this->option('force'));

            $this->line(sprintf(
                '%s (#%d): %s',
                $organization->name ?? 'organization',
                $organization->id,
                $installed === [] ? 'already provisioned' : count($installed).' installed — '.implode(', ', $installed),
            ));
        }

        return self::SUCCESS;
    }
}
