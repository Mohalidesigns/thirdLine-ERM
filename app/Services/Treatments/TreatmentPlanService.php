<?php

namespace App\Services\Treatments;

use App\Events\TreatmentCompleted;
use App\Models\RiskAuditTrail;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Policies\TreatmentPlanPolicy;
use App\Services\AuditTrailService;
use App\Services\NotificationService;
use App\Services\ReferenceCodeService;
use App\Services\Workflow\ModuleApprovals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Treatment plans (migration Phase 3.5).
 *
 * Lifted out of TreatmentPlanController, which computed the dashboard's
 * fourteen figures inline — where no test could reach them — and carried the
 * 200038 form-name → canonical-column mapping as two private consts beside
 * them.
 *
 * The mapping stays exactly one place, here: the create and edit forms render
 * from the TreatmentPlan object type, whose attributes still post
 * treatment_title / treatment_type / … , and the table has held
 * action_title / strategy / … since the canonical backfill. See
 * docs/schema/canonical-columns.md.
 *
 * The approval DECISION is not here. It belongs to TreatmentPlanBinding, so a
 * decision recorded from My Tasks does exactly what one recorded on the plan's
 * own page does; this only routes to it through ModuleApprovals.
 */
class TreatmentPlanService
{
    public function __construct(private readonly ModuleApprovals $approvals) {}

    /* ------------------------------------------------------------------ */
    /*  Column mapping */
    /* ------------------------------------------------------------------ */

    /**
     * Form field => canonical column.
     *
     * @var array<string, string>
     */
    public const FIELD_MAP = [
        'treatment_title' => 'action_title',
        'treatment_description' => 'action_description',
        'treatment_type' => 'strategy',
        'treatment_owner_id' => 'owner_id',
        'target_completion_date' => 'target_date',
        'actual_completion_date' => 'completion_date',
        'estimated_cost' => 'cost_estimate_ngn',
        'actual_cost' => 'actual_cost_ngn',
        'progress_percentage' => 'progress_pct',
        'implementation_notes' => 'progress_notes',
    ];

    /**
     * Form fields that already name canonical columns.
     *
     * Taken by allow-list rather than by unsetting the mapped keys: a new form
     * field then has to be added here deliberately instead of silently landing
     * on a column that may not exist.
     *
     * @var list<string>
     */
    public const PASSTHROUGH_FIELDS = [
        'risk_id',
        'priority',
        'status',
        'expected_residual_likelihood',
        'expected_residual_impact',
        'milestones',
        'success_criteria',
    ];

    /**
     * The validated form as table columns.
     *
     * array_key_exists rather than ?? so that clearing a field on the edit
     * form writes NULL instead of being silently skipped.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function toColumns(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip(self::PASSTHROUGH_FIELDS));

        foreach (self::FIELD_MAP as $formField => $canonical) {
            if (array_key_exists($formField, $validated)) {
                $attributes[$canonical] = $validated[$formField];
            }
        }

        return $attributes;
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * The dashboard's figures, pinned by
     * tests/Feature/Characterisation/TreatmentDashboardStatsTest.
     *
     * `avgEffectiveness` is the mean progress across EVERY plan, not only the
     * ones showing progress. The Blade controller wrote
     * `whereNotNull('progress_pct')->avg(...)`, which reads as "skip the
     * unmeasured ones" but never skipped anything: progress_pct is NOT NULL
     * with a default of 0 (2026_02_22_200010), so a not-started plan is a
     * measured 0 and pulls the mean down. The filter is dropped rather than
     * kept, because a no-op that states a false intent is worse than no
     * filter; the figure it produces is unchanged, and the characterisation
     * test pins it at the value the Blade screen showed.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $completedPlans = $this->scoped()->where('status', 'completed')->count();
        $overduePlans = $this->overdue()->count();

        return [
            'totalPlans' => $this->scoped()->count(),
            'activePlans' => $this->scoped()->whereIn('status', TreatmentPlan::ACTIVE_STATUSES)->count(),
            'completedPlans' => $completedPlans,
            'overduePlans' => $overduePlans,
            'totalBudget' => (float) $this->scoped()
                ->selectRaw('COALESCE(SUM(cost_estimate_ngn), 0) as total')
                ->value('total'),
            'avgEffectiveness' => (int) round((float) $this->scoped()->avg('progress_pct')),
        ];
    }

    /**
     * Budget against actual spend, one row per strategy, always all four in
     * the same order — a strategy nobody has used is a zero row, not a gap,
     * so the chart's bars do not move when a plan is added.
     *
     * @return list<array{strategy: string, label: string, budget: float, actual: float}>
     */
    public function budgetByStrategy(): array
    {
        $rows = $this->scoped()
            ->selectRaw('LOWER(strategy) s, COALESCE(SUM(cost_estimate_ngn), 0) as b, COALESCE(SUM(actual_cost_ngn), 0) as a')
            ->groupBy('s')
            ->get()
            ->keyBy('s');

        return array_map(fn (string $strategy) => [
            'strategy' => $strategy,
            'label' => ucfirst($strategy),
            'budget' => (float) ($rows->get($strategy)->b ?? 0),
            'actual' => (float) ($rows->get($strategy)->a ?? 0),
        ], TreatmentPlan::STRATEGIES);
    }

    /**
     * Plans per strategy, in the same fixed order.
     *
     * @return list<array{strategy: string, label: string, value: int}>
     */
    public function strategyMix(): array
    {
        $counts = $this->scoped()->selectRaw('LOWER(strategy) s, COUNT(*) c')->groupBy('s')->pluck('c', 's');

        return array_map(fn (string $strategy) => [
            'strategy' => $strategy,
            'label' => ucfirst($strategy),
            'value' => (int) ($counts[$strategy] ?? 0),
        ], TreatmentPlan::STRATEGIES);
    }

    /**
     * Plans per status band.
     *
     * The bands OVERLAP, exactly as the Blade chart's did: an overdue plan is
     * counted both under In Progress and under Overdue. Kept because the chart
     * is read as five independent answers to "how many plans are X", not as a
     * partition summing to the total.
     *
     * @return list<array{status: string, label: string, value: int}>
     */
    public function statusMix(): array
    {
        return [
            ['status' => 'not_started', 'label' => 'Not Started', 'value' => $this->scoped()->where('status', 'not_started')->count()],
            ['status' => 'in_progress', 'label' => 'In Progress', 'value' => $this->scoped()->whereIn('status', TreatmentPlan::RUNNING_STATUSES)->count()],
            ['status' => 'completed', 'label' => 'Completed', 'value' => $this->scoped()->where('status', 'completed')->count()],
            ['status' => 'overdue', 'label' => 'Overdue', 'value' => $this->overdue()->count()],
            ['status' => 'on_hold', 'label' => 'On Hold', 'value' => $this->scoped()->where('status', 'on_hold')->count()],
        ];
    }

    /**
     * Plans created and plans completed, per month of the current year.
     *
     * Twelve months always, so the axis does not rescale between tenants. The
     * Blade version ran 24 count queries here; this runs two grouped ones.
     *
     * @return list<array{month: string, created: int, completed: int}>
     */
    public function completionTrend(): array
    {
        $year = now()->year;
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $created = $this->countByMonth($this->scoped()->whereYear('created_at', $year), 'created_at');
        $completed = $this->countByMonth(
            $this->scoped()->where('status', 'completed')->whereYear('updated_at', $year),
            'updated_at',
        );

        return array_map(fn (string $label, int $index) => [
            'month' => $label,
            'created' => (int) ($created[$index + 1] ?? 0),
            'completed' => (int) ($completed[$index + 1] ?? 0),
        ], $labels, array_keys($labels));
    }

    /**
     * @param  Builder<TreatmentPlan>  $query
     * @return array<int, int> month number => count
     */
    private function countByMonth(Builder $query, string $column): array
    {
        return $query->selectRaw($this->monthExpression($column).' as m, COUNT(*) as c')
            ->groupBy('m')
            ->pluck('c', 'm')
            ->map(fn ($count) => (int) $count)
            ->keyBy(fn ($count, $month) => (int) $month)
            ->all();
    }

    /**
     * The month number of a date column.
     *
     * MySQL and SQLite disagree on how to read a month out of a date, and this
     * application runs on both — MySQL in production, SQLite for the test
     * suite. Same shape as DashboardController::monthExpression().
     */
    private function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            'pgsql' => "EXTRACT(MONTH FROM {$column})",
            'sqlsrv' => "MONTH({$column})",
            default => "MONTH({$column})",
        };
    }

    /**
     * The ten active plans closest to their target date.
     *
     * @return Collection<int, TreatmentPlan>
     */
    public function activeTreatments(): Collection
    {
        return $this->scoped()
            ->whereIn('status', TreatmentPlan::ACTIVE_STATUSES)
            ->with(['risk', 'owner'])
            ->orderBy('target_date')
            ->limit(10)
            ->get();
    }

    /**
     * Running plans due inside 30 days — including ones already past due,
     * carried unchanged from the Blade query, which bounded the top of the
     * window only.
     *
     * @return Collection<int, TreatmentPlan>
     */
    public function upcomingDeadlines(): Collection
    {
        return $this->scoped()
            ->whereIn('status', TreatmentPlan::RUNNING_STATUSES)
            ->whereNotNull('target_date')
            ->where('target_date', '<=', now()->addDays(30))
            ->with(['risk', 'owner'])
            ->orderBy('target_date')
            ->limit(10)
            ->get();
    }

    /**
     * The eight most recently touched plans, as an activity feed.
     *
     * Every entry says the same thing — the feed is "what changed", derived
     * from updated_at, because no per-plan activity log exists to read. It is
     * carried across rather than enriched: inventing an action verb the data
     * does not record would be a fabricated figure.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(): array
    {
        return $this->scoped()
            ->with('owner')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (TreatmentPlan $plan) => [
                'id' => $plan->id,
                'description' => 'Treatment plan "'.($plan->title ?? 'Untitled').'" was updated',
                'icon' => 'update',
                'user' => $plan->owner?->name,
                'at' => $plan->updated_at?->diffForHumans(),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Review queue */
    /* ------------------------------------------------------------------ */

    /**
     * Plans waiting on a reviewer. The screen renders Approve and Reject
     * against each one, and both are only valid in pending_review.
     *
     * @return Collection<int, TreatmentPlan>
     */
    public function pendingReview(): Collection
    {
        return $this->scoped()
            ->where('status', 'pending_review')
            ->with(['risk', 'owner'])
            ->orderBy('target_date')
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, ?int $actorId): TreatmentPlan
    {
        return DB::transaction(function () use ($validated, $actorId) {
            $plan = TreatmentPlan::create(array_merge(
                $this->toColumns($this->encodeMilestones($validated)),
                [
                    'organization_id' => TenantContext::organizationId(),
                    'treatment_code' => ReferenceCodeService::generate('treatment_plans', 'treatment_code', 'TP'),
                    'status' => 'not_started',
                    'progress_pct' => 0,
                    'created_by' => $actorId,
                ],
            ));

            AuditTrailService::record($plan, 'create');

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(TreatmentPlan $plan, array $validated, ?int $actorId): TreatmentPlan
    {
        $validated = $this->encodeMilestones($validated);

        // Completing a plan dates it and fills the bar, so "completed" and
        // "100%, finished on ..." can never disagree on the page.
        if (($validated['status'] ?? null) === 'completed' && empty($validated['actual_completion_date'])) {
            $validated['actual_completion_date'] = now()->toDateString();
            $validated['progress_percentage'] = 100;
        }

        $original = $plan->getAttributes();

        $plan->update(array_merge($this->toColumns($validated), ['updated_by' => $actorId]));

        AuditTrailService::recordChanges($plan, $original);

        // Wave 3 listens for this to re-score the risk. Guarded on the
        // TRANSITION, not on the status, so re-saving a completed plan does
        // not re-fire it.
        if (($original['status'] ?? null) !== 'completed' && $plan->status === 'completed' && $plan->risk) {
            TreatmentCompleted::dispatch($plan, $plan->risk);
        }

        return $plan;
    }

    public function destroy(TreatmentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $this->trail($plan, 'deleted', "Treatment plan {$plan->treatment_code} deleted");

            $plan->delete();
        });
    }

    public function comment(TreatmentPlan $plan, string $comment): void
    {
        $this->trail($plan, 'commented', $comment);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * Owner submits a draft for review.
     *
     * The engine raises the task against the approver role and notifies its
     * holders; the fallback below is what happens for a tenant that has not
     * published the definition yet.
     */
    public function submitForReview(TreatmentPlan $plan, User $actor): void
    {
        if ($this->approvals->submit('treatment_plan_approval', $plan, [], $actor)) {
            return;
        }

        $this->approvals->markSubmitted($plan);

        $approvers = User::role(TreatmentPlanPolicy::APPROVER_ROLES)
            ->where('organization_id', $plan->organization_id)
            ->get();

        foreach ($approvers as $approver) {
            NotificationService::send(
                $plan->organization_id,
                $approver->id,
                'approval_request',
                "Treatment plan awaiting review: {$plan->action_title}",
                "Treatment plan #{$plan->id} ({$plan->treatment_code}) has been submitted for review.",
                ['entity_type' => $plan->getMorphClass(), 'entity_id' => $plan->id],
            );
        }
    }

    /**
     * Record an approval. Returns true when the plan reached its final
     * approval, false when it moved on to another step — over the cost
     * threshold a plan goes on to the CRO, and the caller's message says what
     * actually happened rather than assuming.
     */
    public function approve(TreatmentPlan $plan, User $actor, ?string $comments): bool
    {
        if ($this->approvals->decide($plan, 'approve', $actor, ['comments' => $comments])) {
            return $plan->fresh()?->status === 'approved';
        }

        $this->approvals->decideDirectly($plan, 'approve', $actor, $comments);

        return true;
    }

    public function reject(TreatmentPlan $plan, User $actor, string $reason): void
    {
        if (! $this->approvals->decide($plan, 'reject', $actor, ['comments' => $reason])) {
            $this->approvals->decideDirectly($plan, 'reject', $actor, $reason);
        }
    }

    /** A rejected plan goes back to draft for rework. */
    public function returnToDraft(TreatmentPlan $plan): void
    {
        $plan->update(['status' => 'draft', 'rejection_reason' => null]);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return Builder<TreatmentPlan> */
    private function scoped(): Builder
    {
        return TreatmentPlan::query()->where('organization_id', TenantContext::organizationId());
    }

    /** Running plans past their target date. */
    private function overdue(): Builder
    {
        return $this->scoped()
            ->whereIn('status', TreatmentPlan::RUNNING_STATUSES)
            ->whereNotNull('target_date')
            ->where('target_date', '<', now());
    }

    /**
     * Milestones post as a repeating group and are stored as a JSON string.
     * Rows with no title are dropped — the form always renders one empty row.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function encodeMilestones(array $validated): array
    {
        if (isset($validated['milestones'])) {
            $validated['milestones'] = json_encode(array_values(array_filter(
                $validated['milestones'],
                fn ($milestone) => ! empty($milestone['title']),
            )));
        }

        return $validated;
    }

    private function trail(TreatmentPlan $plan, string $action, string $reason): void
    {
        RiskAuditTrail::create([
            'organization_id' => $plan->organization_id,
            'entity_type' => $plan->getMorphClass(),
            'entity_id' => $plan->id,
            'action_type' => $action,
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'change_reason' => $reason,
            'ip_address' => request()->ip(),
        ]);
    }
}
