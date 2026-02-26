<?php

namespace App\Console\Commands;

use App\Models\LossEvent;
use Illuminate\Console\Command;

class CheckRegulatoryDeadlines extends Command
{
    protected $signature = 'regulatory:check-deadlines';

    protected $description = 'Check for approaching regulatory reporting deadlines';

    public function handle(): int
    {
        $this->info('Checking for approaching regulatory deadlines...');

        // Find loss events with upcoming CBN/NFIU reporting deadlines
        $upcomingDeadlines = LossEvent::where('cbn_report_deadline', '<=', now()->addHours(48))
            ->where('cbn_report_deadline', '>', now())
            ->where('cbn_reported', false)
            ->get();

        $urgentCount = 0;

        foreach ($upcomingDeadlines as $lossEvent) {
            $hoursUntilDeadline = now()->diffInHours($lossEvent->cbn_report_deadline);

            // Create urgent notification
            \DB::table('notifications_log')->insert([
                'organization_id' => $lossEvent->organization_id,
                'user_id' => null,
                'channel' => 'database',
                'type' => 'regulatory_deadline_urgent',
                'subject' => "URGENT: CBN Reporting Deadline Approaching - {$lossEvent->event_reference}",
                'body' => "Loss event '{$lossEvent->title}' has a CBN reporting deadline in {$hoursUntilDeadline} hours.",
                'status' => 'sent',
                'metadata' => json_encode([
                    'category' => 'regulatory',
                    'priority' => 'critical',
                    'action_url' => "/risk/loss-events/{$lossEvent->id}",
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $urgentCount++;
            $this->line("Deadline alert sent for {$lossEvent->event_reference} (CBN deadline in {$hoursUntilDeadline} hours).");
        }

        // Check NFIU deadlines
        $nfiuDeadlines = LossEvent::where('nfiu_report_deadline', '<=', now()->addHours(48))
            ->where('nfiu_report_deadline', '>', now())
            ->where('nfiu_reported', false)
            ->get();

        foreach ($nfiuDeadlines as $lossEvent) {
            $hoursUntilDeadline = now()->diffInHours($lossEvent->nfiu_report_deadline);

            \DB::table('notifications_log')->insert([
                'organization_id' => $lossEvent->organization_id,
                'user_id' => null,
                'channel' => 'database',
                'type' => 'regulatory_deadline_urgent',
                'subject' => "URGENT: NFIU Reporting Deadline Approaching - {$lossEvent->event_reference}",
                'body' => "Loss event '{$lossEvent->title}' has an NFIU reporting deadline in {$hoursUntilDeadline} hours.",
                'status' => 'sent',
                'metadata' => json_encode([
                    'category' => 'regulatory',
                    'priority' => 'critical',
                    'action_url' => "/risk/loss-events/{$lossEvent->id}",
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $urgentCount++;
            $this->line("Deadline alert sent for {$lossEvent->event_reference} (NFIU deadline in {$hoursUntilDeadline} hours).");
        }

        $this->info("Checked regulatory deadlines. Sent {$urgentCount} urgent notifications.");

        return self::SUCCESS;
    }
}
