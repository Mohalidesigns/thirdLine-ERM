<?php

namespace App\Console\Commands;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Organization;
use App\Services\Bcms\Training\TrainingComplianceService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Links a completed exercise occurrence's present participants to their
 * training records — phase-11-spec §6 criterion 6.
 *
 * A WARDEN WHO RAN A DRILL HAS DEMONSTRATED COMPETENCE, and this is the
 * mechanism that makes that automatic rather than something a coordinator
 * remembers to do by hand. It DOES NOT set `competency_assessed` — a machine
 * cannot assert somebody is competent, only an assessor can, and that stays a
 * human act.
 *
 * IDEMPOTENT, SO A DAILY SWEEP IS SAFE. `linkOccurrenceParticipants()` skips
 * an occurrence/curriculum/user combination that already has a record, so
 * running this against the same week of occurrences twice creates nothing
 * twice.
 */
class LinkBcmsTrainingFromOccurrences extends Command
{
    protected $signature = 'bcms:training-link-exercises {--days=7 : How many days back to look for completed occurrences}';

    protected $description = 'Link completed exercise occurrences to participants\' training records.';

    public function handle(TrainingComplianceService $training): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to link.');

            return self::SUCCESS;
        }

        $since = now()->subDays((int) $this->option('days'));
        $totalLinked = 0;

        foreach (Organization::query()->get() as $organization) {
            TenantContext::set($organization->id);

            try {
                $occurrences = ExerciseOccurrence::query()
                    ->where('status', 'completed')
                    ->where('updated_at', '>=', $since)
                    ->get();

                foreach ($occurrences as $occurrence) {
                    $totalLinked += $training->linkOccurrenceParticipants($occurrence);
                }
            } finally {
                TenantContext::clear();
            }
        }

        $this->info("{$totalLinked} training record(s) linked from recently completed occurrences.");

        return self::SUCCESS;
    }
}
