<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\LossEvents\LossEventReturnService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

class OpenLossReturnsCycle extends Command
{
    protected $signature = 'loss:open-returns
                            {--period= : The monthly period code in Y-m format (e.g. 2026-11)}
                            {--organization= : Only this organization id}';

    protected $description = 'Open and initialize the monthly Loss Event returns cycle for all departments';

    public function handle(LossEventReturnService $service): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');

        if (! preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $period)) {
            $this->error('--period must be in Y-m format (e.g. 2026-11)');

            return self::INVALID;
        }

        $only = $this->option('organization');

        $organizationIds = TenantContext::bypass(
            fn () => Organization::query()
                ->where('is_active', true)
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'loss:open-returns organization sweep'
        );

        foreach ($organizationIds as $orgId) {
            TenantContext::actingAs((int) $orgId, function () use ($service, $orgId, $period) {
                $returns = $service->ensureReturnsForPeriod((int) $orgId, $period);
                $this->line("Organization {$orgId} ({$period}): Initialized {$returns->count()} departmental loss event returns.");
            });
        }

        $this->info("Monthly loss event returns cycle initialized for period {$period}.");

        return self::SUCCESS;
    }
}
