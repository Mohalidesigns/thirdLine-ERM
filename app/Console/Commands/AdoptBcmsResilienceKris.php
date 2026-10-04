<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bcms\ResilienceKriPublisher;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Seeds the seventeen resilience KRI definitions for every tenant with BCMS
 * enabled (ADR 0021 §2, phase-11-spec §2.5).
 *
 * RUN ONCE PER TENANT ON MODULE ENABLE, AND SAFE TO RE-RUN. `adopt()` is
 * idempotent on `kri_code`; a re-run after this catalogue gains an eighteenth
 * definition adds only the new one, and never touches a threshold a tenant
 * has since tuned. There is deliberately no schedule entry for this command —
 * creating seventeen KRIs in a bank's own risk register is a change to their
 * risk framework, not a routine reporting side effect, so it is invoked
 * explicitly (module enable, or by an administrator), matching TPRM's own
 * `tprm:publish-kris --adopt`-style precedent of never adopting silently.
 */
class AdoptBcmsResilienceKris extends Command
{
    protected $signature = 'bcms:kri:adopt {--organization= : Restrict to one organisation id}';

    protected $description = 'Adopt the seventeen BCMS resilience KRI definitions for tenants with BCMS enabled.';

    public function handle(ResilienceKriPublisher $publisher): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to adopt.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $result = $publisher->adopt();

                $this->line(sprintf(
                    '%s: %d created, %d already present.',
                    $organization->name, $result['created'], $result['existing'],
                ));
            } finally {
                TenantContext::clear();
            }
        }

        return self::SUCCESS;
    }
}
