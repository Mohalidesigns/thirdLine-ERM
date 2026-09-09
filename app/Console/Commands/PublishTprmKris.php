<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Tprm\Reporting\KriPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The third-party KRI sweep — FR-RPT-10.
 *
 * IT RUNS AFTER EVERY OTHER TPRM SWEEP. Seven of the nine metrics read state
 * the earlier commands have just changed: a finding raised by the obligation
 * sweep at 07:25 must be in the average age published at 08:15, not tomorrow's.
 *
 * `--adopt` IS SEPARATE AND DELIBERATE. Creating nine KRIs in a tenant's
 * register is a change to their risk framework, not a reporting side effect,
 * so the scheduled sweep never does it. A programme that has not adopted them
 * publishes nothing and the command says so.
 *
 * A SKIPPED METRIC IS NOT A FAILURE AND IS NOT SUCCESS EITHER. It is a metric
 * with no computable value — no Critical vendors, no executed contracts — and
 * the command prints each one with its reason, because a run reporting "9
 * published" when four were skipped would be the fabrication this whole
 * feature is built to avoid.
 */
class PublishTprmKris extends Command
{
    protected $signature = 'tprm:publish-kris
        {--adopt : Create any missing KRI in each tenant register and link it}
        {--date= : Record the reading against this date}';

    protected $description = 'Publish the nine third-party KRIs into the KRI Collection module';

    public function handle(KriPublisher $publisher): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $on = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::now();

        $failed = 0;

        foreach (Organization::query()->where('is_active', true)->get() as $organization) {
            TenantContext::actingAs($organization->id, function () use ($publisher, $on, $organization, &$failed) {
                if ($this->option('adopt')) {
                    $adoption = $publisher->adopt();
                    $this->line(sprintf(
                        '%s: %d KRIs created, %d already linked.',
                        $organization->name,
                        $adoption['created'],
                        $adoption['existing'],
                    ));
                }

                $result = $publisher->publish($on);

                $this->info(sprintf(
                    '%s: %d published, %d skipped, %d failed.',
                    $organization->name,
                    $result['published'],
                    $result['skipped'],
                    $result['failed'],
                ));

                foreach ($result['details'] as $detail) {
                    if ($detail['outcome'] === 'published') {
                        continue;
                    }

                    // Every skip is named with its reason. A run reporting
                    // only its successes would hide exactly the metrics whose
                    // absence somebody needs to know about.
                    $this->warn(sprintf(
                        '  %s %s: %s',
                        $detail['outcome'] === 'failed' ? 'FAILED' : 'skipped',
                        $detail['name'] ?? $detail['metric'],
                        $detail['note'],
                    ));
                }

                $failed += $result['failed'];
            });
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
