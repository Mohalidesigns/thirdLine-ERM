<?php

namespace App\Console\Commands;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureThreshold;
use App\Models\Organization;
use App\Models\Period;
use App\Models\User;
use App\Services\KriMeasureBridge;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

class SeedKriReadings extends Command
{
    protected $signature = 'kri:seed-readings
                            {--organization= : Only this organization id}
                            {--limit= : Limit the number of KRIs processed per organization}';

    protected $description = 'Seed backdated KRI readings and trend analysis data since December 2025';

    public function handle(KriMeasureBridge $bridge): int
    {
        $only = $this->option('organization');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $orgIds = TenantContext::bypass(
            fn () => Organization::query()
                ->where('is_active', true)
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'kri:seed-readings organization sweep'
        );

        // The 12 periods backdating since December 2025 through November 2026
        $months = [
            '2025-12-15',
            '2026-01-15',
            '2026-02-15',
            '2026-03-15',
            '2026-04-15',
            '2026-05-15',
            '2026-06-15',
            '2026-07-15',
            '2026-08-15',
            '2026-09-15',
            '2026-10-15',
            '2026-11-15',
        ];

        foreach ($orgIds as $orgId) {
            TenantContext::actingAs((int) $orgId, function () use ($bridge, $orgId, $months, $limit) {
                $user = User::where('organization_id', $orgId)->first();
                $actorId = $user?->id;

                $query = KeyRiskIndicator::query()
                    ->where('organization_id', $orgId)
                    ->where('is_active', true)
                    ->orderBy('id');

                if ($limit) {
                    $query->limit($limit);
                }

                $kris = $query->get();
                $this->info("Processing {$kris->count()} KRIs for Organization {$orgId} across 12 trailing months (Dec 2025 - Nov 2026)...");

                $bar = $this->output->createProgressBar($kris->count());
                $bar->start();

                foreach ($kris as $kri) {
                    $measure = $bridge->measureFor($kri) ?? $bridge->syncDefinition($kri);
                    if ($measure) {
                        MeasureThreshold::withoutGlobalScopes()
                            ->where('measure_id', $measure->id)
                            ->whereNull('supersedes_id')
                            ->where('effective_from', '>', '2025-01-01')
                            ->update(['effective_from' => '2025-01-01']);
                    }

                    $green = (float) ($kri->green_threshold ?? 10);
                    $red = (float) ($kri->red_threshold ?? 20);
                    $lowerIsWorse = $kri->threshold_direction === 'lower_worse';

                    $span = abs($red - $green);
                    if ($span == 0) {
                        $span = max(abs($green) * 0.2, 5);
                    }

                    // Base target near green
                    $base = $lowerIsWorse ? ($green + $span * 0.3) : ($green - $span * 0.3);

                    // Generate a 12-month walk
                    $currentVal = $base;
                    $stepIndex = 0;

                    foreach ($months as $dateString) {
                        $date = CarbonImmutable::parse($dateString);

                        // Random small fluctuation
                        $fluctuation = ($span * 0.15) * sin($stepIndex * 0.6) + (($kri->id + $stepIndex) % 7 - 3) * ($span * 0.05);
                        $readingVal = round(max(0, $currentVal + $fluctuation), 4);

                        // Notes based on month
                        $notes = 'Monthly measurement for ' . $date->format('F Y');

                        try {
                            $bridge->recordMeasurement($kri, $date, $readingVal, [
                                'entered_by' => $actorId,
                                'notes' => $notes,
                                'source' => 'system_seed',
                            ]);
                        } catch (\Throwable $e) {
                            // Continue on any single measurement error
                        }

                        $stepIndex++;
                    }

                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            });
        }

        $this->info('KRI historical readings and trend analysis data seeded successfully since December 2025.');

        return self::SUCCESS;
    }
}
