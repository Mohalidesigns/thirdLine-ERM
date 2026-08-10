<?php

namespace App\Console\Commands;

use App\Models\TreatmentPlan;
use Illuminate\Console\Command;

class CheckOverdueTreatments extends Command
{
    protected $signature = 'treatments:check-overdue';

    protected $description = 'Check for overdue treatment plans and update status';

    public function handle(): int
    {
        $this->info('Checking for overdue treatment plans...');

        // Find all treatments past target completion date that aren't completed
        $overdueTreatments = TreatmentPlan::where('status', '!=', 'completed')
            ->where('target_date', '<', now())
            ->get();

        if ($overdueTreatments->isEmpty()) {
            $this->info('No overdue treatments found.');

            return self::SUCCESS;
        }

        foreach ($overdueTreatments as $treatment) {
            $daysOverdue = now()->diffInDays($treatment->target_date);

            // Update status to overdue if not already
            if ($treatment->status !== 'overdue') {
                $treatment->update([
                    'status' => 'overdue',
                    'last_updated_at' => now(),
                ]);

                // Create notification
                \DB::table('notifications_log')->insert([
                    'organization_id' => $treatment->organization_id,
                    'user_id' => $treatment->responsible_user_id,
                    'channel' => 'database',
                    'type' => 'treatment_overdue',
                    'subject' => "Treatment Plan Overdue: {$treatment->treatment_code}",
                    'body' => "Treatment plan '{$treatment->action_title}' is {$daysOverdue} days overdue.",
                    'status' => 'sent',
                    'metadata' => json_encode([
                        'category' => 'treatment',
                        'priority' => $daysOverdue > 30 ? 'critical' : 'high',
                        'action_url' => "/risk/treatments/{$treatment->id}",
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->line("Treatment {$treatment->treatment_code} marked as overdue ({$daysOverdue} days).");
            }
        }

        $this->info("Checked treatment plans. Updated {$overdueTreatments->count()} to overdue status.");

        return self::SUCCESS;
    }
}
