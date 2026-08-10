<?php

namespace App\Services\Workflow;

use App\Models\WorkflowDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Makes workflow_definitions.trigger a column something reads.
 *
 * WP-06 exists partly because escalation_rules was written by a designer and
 * read by nothing. Shipping a `trigger` column with the same property would be
 * repeating the mistake in a new table, so every value in the enum does
 * something:
 *
 *   manual         a caller starts it — ModuleApprovals::submit()
 *   on_create      the subject being created starts it
 *   on_transition  a change to the subject's status column starts it
 *   on_event       a domain event starts it; the caller names the code
 *   on_schedule    a scheduled command starts it over everything in scope
 *
 * SCOPE FILTER. A definition may narrow what it fires over —
 * {"issue_category": "regulatory", "priority": ["high", "critical"]} — matched
 * against the subject's own attributes. Without it, "start a workflow when an
 * issue is created" means every issue, which is how a well-meant automation
 * becomes ten thousand tasks nobody asked for.
 */
class WorkflowTriggerService
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * A subject was created. Start any definition that says so.
     *
     * @return int how many instances were started
     */
    public function handleCreated(Model $subject): int
    {
        return $this->fire($subject, 'on_create', fn () => true);
    }

    /**
     * A subject's lifecycle column moved.
     *
     * trigger_config may name {from, to}; a definition that names neither
     * fires on any transition, which is almost never what somebody means, so
     * `to` is effectively required in practice and the log says when it is
     * missing.
     */
    public function handleTransition(Model $subject, ?string $from, ?string $to): int
    {
        return $this->fire($subject, 'on_transition', function (WorkflowDefinition $definition) use ($from, $to, $subject) {
            $config = (array) $definition->trigger_config;
            $wantFrom = $config['from'] ?? null;
            $wantTo = $config['to'] ?? null;

            if ($wantTo === null && $wantFrom === null) {
                Log::warning('A workflow definition triggers on any transition, which is rarely intended.', [
                    'definition' => $definition->code,
                    'subject' => $subject->getMorphClass().'#'.$subject->getKey(),
                ]);

                return true;
            }

            if ($wantFrom !== null && ! in_array($from, (array) $wantFrom, true)) {
                return false;
            }

            return $wantTo === null || in_array($to, (array) $wantTo, true);
        });
    }

    /**
     * A domain event fired. Definitions naming it in trigger_config.event start.
     */
    public function handleEvent(string $event, Model $subject): int
    {
        return $this->fire($subject, 'on_event', fn (WorkflowDefinition $definition) => in_array(
            $event,
            (array) (data_get($definition->trigger_config, 'event') ?? []),
            true
        ));
    }

    /**
     * @param  callable(WorkflowDefinition): bool  $applies
     */
    private function fire(Model $subject, string $trigger, callable $applies): int
    {
        $organizationId = $subject->getAttribute('organization_id') ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return 0;
        }

        $definitions = WorkflowDefinition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $subject->getMorphClass())
            ->where('trigger', $trigger)
            ->published()
            ->orderBy('code')
            ->get();

        $started = 0;

        foreach ($definitions as $definition) {
            if (! $applies($definition) || ! $this->inScope($definition, $subject)) {
                continue;
            }

            // Never a second review of the same thing: two open instances over
            // one subject means two competing sets of tasks and no defensible
            // answer to "was this approved".
            if ($this->engine->openInstanceFor($subject) !== null) {
                continue;
            }

            try {
                if ($this->engine->start($definition, $subject) !== null) {
                    $started++;
                }
            } catch (\Throwable $e) {
                // A triggered workflow that throws must not take the save that
                // triggered it down with it. Recording a loss event has to work
                // whether or not somebody's automation is well-formed.
                Log::error('A triggered workflow could not be started.', [
                    'definition' => $definition->code,
                    'subject' => $subject->getMorphClass().'#'.$subject->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }

    /**
     * Does the definition's scope_filter match this subject?
     *
     * {"column": "value"} or {"column": ["a", "b"]}. An absent filter matches
     * everything.
     */
    private function inScope(WorkflowDefinition $definition, Model $subject): bool
    {
        foreach ((array) $definition->scope_filter as $column => $expected) {
            $actual = $subject->getAttribute($column);

            if (is_array($expected)) {
                if (! in_array($actual, $expected)) {
                    return false;
                }

                continue;
            }

            if ($actual != $expected) {
                return false;
            }
        }

        return true;
    }
}
