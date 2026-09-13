<?php

namespace App\Grids\Definitions;

use App\Enums\Llm\Outcome;
use App\Grids\Column;
use App\Grids\GridDefinition;
use App\Models\LlmUsageEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The recent-calls panel on `Tprm/Settings/AiUsage` — phase-11a-ai-contract.md
 * §7.3, `docs/tprm/screens/ai-usage.md` §2.
 *
 * SCOPED TO THE SELECTED MONTH VIA THE `month` QUERY PARAMETER, not the
 * grid's own filter row — the screen spec's month selector is a plain
 * Inertia `GET` navigation with `?month=`, not the grid's `filters[...]`
 * convention, so `query()` reads it directly the same way every other TPRM
 * grid definition reads `TenantContext::organizationId()` from ambient
 * request state rather than a constructor argument (`GridRegistry::resolve()`
 * takes no arguments).
 */
class TprmAiUsageEventsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_ai_usage_events';
    }

    public function permission(): string
    {
        return 'tprm.admin';
    }

    public function query(): Builder
    {
        $month = (string) (request()->query('month') ?: now()->format('Y-m'));

        return LlmUsageEvent::query()
            ->with('user:id,name')
            ->where('llm_usage_events.organization_id', TenantContext::organizationId())
            ->where('llm_usage_events.module', 'tprm')
            ->where('llm_usage_events.usage_month', $month);
    }

    public function columns(): array
    {
        return [
            Column::make('created_at', 'When')
                ->sortable()->datetime(),

            Column::make('service', 'Service')
                ->sortable()->searchable(),

            Column::make('outcome', 'Outcome')
                ->sortable()
                ->using(fn (LlmUsageEvent $e) => $e->outcome->label())
                ->rag([
                    Outcome::Succeeded->label() => 'green',
                    Outcome::Refused->label() => 'neutral',
                    Outcome::CircuitOpen->label() => 'amber',
                    Outcome::CapExceeded->label() => 'amber',
                    Outcome::Unreachable->label() => 'red',
                    Outcome::Timeout->label() => 'red',
                    Outcome::HttpError->label() => 'red',
                    Outcome::Unparsable->label() => 'amber',
                ]),

            Column::make('model', 'Model')
                ->searchable(),

            Column::make('endpoint_profile', 'Endpoint profile')
                ->searchable(),

            Column::make('total_tokens', 'Tokens')
                ->sortable()
                ->using(fn (LlmUsageEvent $e) => $e->total_tokens === null ? 'not reported' : (string) $e->total_tokens),

            Column::make('duration_ms', 'Duration')
                ->sortable()
                ->using(fn (LlmUsageEvent $e) => round($e->duration_ms / 1000, 1).'s'),

            Column::make('subject_type', 'Subject')
                ->using(fn (LlmUsageEvent $e) => $this->subjectLabel($e)),

            // Gate 2 blocking defect 3: `user_id` used to have no writer, so
            // every row — including every genuinely user-initiated call —
            // rendered here as "Scheduled": an absence stated as a fact
            // ("we checked, and a schedule ran this") rather than as what it
            // actually was ("nobody recorded who, if anyone"). Now that
            // `LlmClient::run()` and `BcmsLlmClient::json()` thread the actor
            // through, a genuinely unattributed row (a path this build does
            // not yet reach) says "Not recorded" — never a specific claim
            // this column cannot back up.
            Column::make('user_id', 'User')
                // `??` is an isset-fetch: a null `loadedUser()` yields the fallback
                // without touching `->name`, so no nullsafe arrow is needed — PHPStan
                // flags one here on ANY nullable type, not only larastan's relations.
                ->using(fn (LlmUsageEvent $e) => $e->loadedUser()->name ?? 'Not recorded'),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No AI calls recorded for this month.';
    }

    /**
     * Plain text, never a raw class name or a numeric-only reference —
     * `docs/tprm/screens/ai-usage.md` §2. A usage row does not grant
     * visibility into a record the viewer could not otherwise open, so this
     * renders as text rather than a link; the frontend decides whether the
     * text also becomes a link once it can check the viewer's own
     * permission on the resolved record.
     */
    private function subjectLabel(LlmUsageEvent $event): string
    {
        if ($event->subject_type === null || $event->subject_id === null) {
            return '—';
        }

        $class = Relation::getMorphedModel($event->subject_type) ?? $event->subject_type;
        $short = class_basename($class);

        return "{$short} #{$event->subject_id}";
    }
}
