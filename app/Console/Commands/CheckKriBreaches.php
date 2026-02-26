<?php

namespace App\Console\Commands;

use App\Events\KriBreachDetected;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use Illuminate\Console\Command;

class CheckKriBreaches extends Command
{
    protected $signature = 'kri:check-breaches';

    protected $description = 'Check KRI measurements against thresholds and dispatch breach events';

    public function handle(): int
    {
        $this->info('Checking for KRI breaches...');

        // Get all active KRIs
        $kris = KeyRiskIndicator::where('status', 'active')->get();

        if ($kris->isEmpty()) {
            $this->info('No active KRIs found.');
            return self::SUCCESS;
        }

        $breachCount = 0;

        foreach ($kris as $kri) {
            // Get the latest measurement
            $latestMeasurement = $kri->measurements()
                ->latest('measurement_date')
                ->first();

            if (!$latestMeasurement) {
                continue;
            }

            $value = $latestMeasurement->value;
            $breachLevel = null;

            // Check against red threshold
            if ($kri->red_threshold !== null) {
                if ($kri->threshold_comparison === 'greater_than' && $value > $kri->red_threshold) {
                    $breachLevel = 'red';
                } elseif ($kri->threshold_comparison === 'less_than' && $value < $kri->red_threshold) {
                    $breachLevel = 'red';
                }
            }

            // Check against amber threshold if not already red
            if (!$breachLevel && $kri->amber_threshold !== null) {
                if ($kri->threshold_comparison === 'greater_than' && $value > $kri->amber_threshold) {
                    $breachLevel = 'amber';
                } elseif ($kri->threshold_comparison === 'less_than' && $value < $kri->amber_threshold) {
                    $breachLevel = 'amber';
                }
            }

            if ($breachLevel) {
                KriBreachDetected::dispatch($kri, $latestMeasurement, $breachLevel);
                $breachCount++;
                $this->line("KRI {$kri->kri_code} breached {$breachLevel} threshold with value {$value}.");
            }
        }

        $this->info("Checked {$kris->count()} KRIs. Found {$breachCount} breaches.");

        return self::SUCCESS;
    }
}
