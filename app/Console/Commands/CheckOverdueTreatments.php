<?php

namespace App\Console\Commands;

use App\Models\TreatmentPlan;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $marked = 0;
        $failed = 0;

        foreach ($overdueTreatments as $treatment) {
            if ($treatment->status === 'overdue') {
                continue;
            }

            // ONE PLAN MUST NOT TAKE THE SWEEP DOWN WITH IT. The previous
            // version updated the status and then inserted the notification,
            // outside any transaction and with nothing catching. The insert
            // threw on the first plan of every run, so the nightly job marked
            // exactly one plan overdue and died — the rest of the register kept
            // its stale status and nobody was told about any of it.
            //
            // The transaction is the other half: a plan is either marked and
            // announced, or neither. A status change nobody was told about is
            // the failure mode that gets an overdue register quietly ignored.
            try {
                DB::transaction(function () use ($treatment, &$marked) {
                    // Carbon 3 defaults $absolute to FALSE (it was true in
                    // Carbon 2), so now()->diffInDays($pastDate) is a NEGATIVE
                    // float. The body used to read "is -14.3958333 days
                    // overdue" and `$daysOverdue > 30` could never be true, so
                    // no plan was ever critical. Same call shape as
                    // CheckOverdueIssues.
                    $daysOverdue = (int) now()->diffInDays($treatment->target_date, true);

                    // `last_updated_at` used to be written here. There is no
                    // such column and it is not fillable, so Eloquent discarded
                    // it silently; `updated_at` is what the intent was and the
                    // model maintains that itself.
                    $treatment->update(['status' => 'overdue']);

                    $this->announce($treatment, $daysOverdue);

                    $marked++;

                    $this->line("Treatment {$treatment->treatment_code} marked as overdue ({$daysOverdue} days).");
                });
            } catch (\Throwable $e) {
                $failed++;

                logger()->error('Overdue treatment sweep failed for one plan', [
                    'treatment_plan_id' => $treatment->id,
                    'error' => $e->getMessage(),
                ]);

                $this->warn("Treatment plan #{$treatment->id} could not be processed: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$overdueTreatments->count()} treatment plan(s). Marked {$marked} overdue.");

        if ($failed > 0) {
            $this->warn("{$failed} plan(s) could not be processed — see the log.");
        }

        return self::SUCCESS;
    }

    /**
     * Tell the plan's owner.
     *
     * Through NotificationService, not a hand-rolled insert: the service is
     * what writes notification_category, priority and action_url into the
     * COLUMNS NotificationController reads. The old insert put those three in
     * the metadata JSON and left the columns null, which made every treatment
     * notification uncategorised and unclickable.
     */
    private function announce(TreatmentPlan $treatment, int $daysOverdue): void
    {
        // The recipient used to be read from `responsible_user_id`, which
        // treatment_plans has never had and the model does not expose — so it
        // was always null against a NOT NULL, foreign-keyed column. `owner_id`
        // is the canonical owner (treatment_owner_id is the losing side of that
        // pair; see the 110002 backfill).
        $ownerId = $treatment->owner_id;

        if ($ownerId === null) {
            // A real data condition, not an error: a plan with no owner has
            // nobody to tell. It is still marked overdue.
            logger()->info('Overdue treatment plan has no owner to notify', [
                'treatment_plan_id' => $treatment->id,
                'organization_id' => $treatment->organization_id,
            ]);

            return;
        }

        NotificationService::send(
            organizationId: (int) $treatment->organization_id,
            userId: (int) $ownerId,
            type: 'treatment_overdue',
            subject: "Treatment Plan Overdue: {$treatment->treatment_code}",
            body: "Treatment plan '{$treatment->action_title}' is {$daysOverdue} days overdue.",
            metadata: ['entity_type' => 'treatment', 'entity_id' => $treatment->id],
            actionUrl: "/risk/treatments/{$treatment->id}",
            priority: $daysOverdue > 30 ? 'critical' : 'high',
            category: 'treatment',
        );
    }
}
