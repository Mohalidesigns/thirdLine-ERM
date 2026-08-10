<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\WorkflowDefinition;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Start the workflows whose trigger is a schedule.
 *
 * The remaining trigger value, so every entry in the enum does something.
 * trigger_config names the cadence — {"cadence": "monthly"} — and scope_filter
 * narrows what it runs over. The canonical use is a periodic re-attestation:
 * "every quarter, every accepted risk comes back for a fresh decision", which
 * is the control that stops an acceptance quietly becoming permanent.
 */
class RunScheduledWorkflows extends Command
{
    protected $signature = 'workflow:run-scheduled
                            {--cadence=daily : Only definitions declaring this cadence}
                            {--dry-run : Report what would start without starting anything}';

    protected $description = 'Start workflows whose definition triggers on a schedule';

    public function handle(WorkflowEngine $engine): int
    {
        $cadence = (string) $this->option('cadence');
        $dryRun = (bool) $this->option('dry-run');
        $started = 0;

        Organization::query()->withoutGlobalScopes()->orderBy('id')->each(
            function (Organization $organization) use ($engine, $cadence, $dryRun, &$started) {
                TenantContext::actingAs($organization->id, function () use ($organization, $engine, $cadence, $dryRun, &$started) {
                    $definitions = WorkflowDefinition::withoutGlobalScopes()
                        ->where('organization_id', $organization->id)
                        ->where('trigger', 'on_schedule')
                        ->published()
                        ->get()
                        ->filter(fn (WorkflowDefinition $d) => ($d->trigger_config['cadence'] ?? 'daily') === $cadence);

                    foreach ($definitions as $definition) {
                        $started += $this->runDefinition($definition, $engine, $dryRun);
                    }
                });
            }
        );

        $this->info(($dryRun ? 'Would start ' : 'Started ').$started.' workflow '
            .\Illuminate\Support\Str::plural('instance', $started).'.');

        return self::SUCCESS;
    }

    private function runDefinition(WorkflowDefinition $definition, WorkflowEngine $engine, bool $dryRun): int
    {
        $class = Relation::getMorphedModel($definition->entity_type);

        if ($class === null || ! class_exists($class)) {
            $this->warn("Definition [{$definition->code}] runs over an unknown record type [{$definition->entity_type}].");

            return 0;
        }

        $query = $class::query()->where('organization_id', $definition->organization_id);

        foreach ((array) $definition->scope_filter as $column => $expected) {
            is_array($expected) ? $query->whereIn($column, $expected) : $query->where($column, $expected);
        }

        // A cap, and it is LOGGED rather than silent: a scoping mistake that
        // would otherwise open ten thousand tasks stops at the limit and says
        // so, which is a recoverable morning instead of a ruined one.
        $limit = (int) ($definition->trigger_config['limit'] ?? 500);
        $subjects = $query->orderBy('id')->limit($limit + 1)->get();

        if ($subjects->count() > $limit) {
            $this->warn("Definition [{$definition->code}] matched more than {$limit} records; "
                .'stopping at the limit. Narrow its scope filter or raise trigger_config.limit.');
            $subjects = $subjects->take($limit);
        }

        $started = 0;

        foreach ($subjects as $subject) {
            if ($engine->openInstanceFor($subject) !== null) {
                continue;
            }

            if (! $dryRun) {
                $engine->start($definition, $subject);
            }

            $started++;
        }

        $this->line(sprintf('  %s: %d', $definition->code, $started));

        return $started;
    }
}
