<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Database\Seeder;

/**
 * WP-06 — install the shipped processes for every seeded organization.
 *
 * The upgrade migration provisions existing tenants; this covers a fresh
 * install, where the organizations do not exist yet when the migrations run.
 * Idempotent, so re-seeding a demo environment does not duplicate anything.
 */
class WorkflowDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $publisher = app(WorkflowPublisher::class);

        Organization::query()->withoutGlobalScopes()->orderBy('id')->each(
            function (Organization $organization) use ($publisher) {
                $installed = $publisher->provision($organization->id);

                $this->command?->info(sprintf(
                    'Workflows for %s: %s',
                    $organization->name ?? ('organization #'.$organization->id),
                    $installed === [] ? 'already provisioned' : count($installed).' installed',
                ));
            }
        );
    }
}
