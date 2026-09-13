<?php

namespace App\Console\Commands;

use App\Services\Tprm\Monitoring\AlertEngine;
use App\Services\Tprm\Monitoring\InternalSignalGenerator;
use Illuminate\Console\Command;

/**
 * The daily monitoring sweep — FR-MON-09 and the acceptance criterion for this
 * phase: "with every external driver disabled, the internal generator produces
 * signals within 24 hours of seeding overdue fixtures, and at least one alert
 * rule fires and creates a finding."
 *
 * TWO STEPS, IN ORDER AND SEPARATE. Deriving signals and acting on them are
 * different jobs with different failure modes: a derivation that throws should
 * not stop the rules from acting on the signals already stored, and a rule
 * that throws should not stop tomorrow's derivation. Running them as one pass
 * would couple the two.
 *
 * THE INTERNAL GENERATOR RUNS WHETHER OR NOT ANY EXTERNAL SOURCE IS
 * CONFIGURED. That is the whole claim of FR-MON-09: continuous monitoring on
 * day one for a client with no data budget.
 */
class RunTprmMonitoring extends Command
{
    protected $signature = 'tprm:run-monitoring
        {--dry-run : Derive signals and report what would fire, without acting}
        {--organization= : Limit to one tenant}';

    protected $description = 'Derive internal monitoring signals and evaluate the alert rules against them';

    public function handle(InternalSignalGenerator $generator, AlertEngine $engine): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $organizationId = $this->option('organization') === null
            ? null
            : (int) $this->option('organization');

        $signals = $generator->generate($organizationId);

        $this->info(sprintf('%d new internal signal(s) derived.', $signals->count()));

        foreach ($signals->groupBy(fn ($signal) => $signal->signal_type->value) as $type => $group) {
            $this->line(sprintf('  %-26s %d', $type, $group->count()));
        }

        if ($this->option('dry-run')) {
            $this->comment('[dry run] The alert rules were not evaluated.');

            return self::SUCCESS;
        }

        $alerts = $engine->process($organizationId);

        $findings = $alerts->filter(fn ($alert) => $alert->created_finding_id !== null)->count();
        $assessments = $alerts->filter(fn ($alert) => $alert->created_assessment_id !== null)->count();

        $this->info(sprintf(
            '%d alert(s) fired: %d finding(s) raised, %d targeted assessment(s) built.',
            $alerts->count(),
            $findings,
            $assessments,
        ));

        return self::SUCCESS;
    }
}
