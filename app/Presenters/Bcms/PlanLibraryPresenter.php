<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\PlanType;
use App\Models\Bcms\Plan;
use App\Models\User;
use App\Services\Bcms\Plans\PlanService;
use App\Support\Rcsa\RcsaScope;

/**
 * The plan library — every plan, its version, its owner and whether it is
 * current.
 *
 * THE STALENESS BADGE IS THE SCREEN'S REASON FOR EXISTING. A library that lists
 * plans in alphabetical order is a folder; one that opens on "four of your
 * eleven plans are past review and two have drifted from the BIA" is a work
 * queue. Everything else on this screen is navigation.
 *
 * AN EMPTY LIBRARY EXPLAINS ITSELF. A user assigned to no business unit sees
 * organisation-level plans and nothing else, and `RcsaScope::describe()` is what
 * turns that from an apparently broken screen into a sentence.
 */
class PlanLibraryPresenter
{
    public function __construct(
        private PlanService $plans,
        private RcsaScope $scope,
    ) {}

    /** @return array<string, mixed> */
    public function present(?User $user, array $filters = []): array
    {
        $query = $this->plans->libraryQuery()
            ->visibleTo($user)
            ->with(['owner:id,name', 'approver:id,name', 'businessUnit:id,name', 'site:id,name'])
            ->withCount([
                'sections',
                'sections as drifted_sections_count' => fn ($q) => $q->where('needs_review', true),
            ]);

        if (filled($filters['type'] ?? null)) {
            $query->where('plan_type', $filters['type']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        } else {
            // Archived versions are history and are reached through a plan's
            // own version chain. Listing every superseded version by default
            // would bury the eleven current plans under forty old ones.
            $query->where('status', '!=', 'archived');
        }

        $plans = $query
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 WHEN status = 'review' THEN 1 ELSE 2 END")
            ->orderBy('title')
            ->get();

        $today = now()->startOfDay();

        return [
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->getKey(),
                'uuid' => $plan->uuid,
                'title' => $plan->title,
                'plan_type' => $plan->plan_type->value,
                'plan_type_label' => $plan->plan_type->label(),
                'version' => $plan->version,
                'status' => $plan->status,
                'owner' => $plan->owner?->name,
                'approver' => $plan->approver?->name,
                'business_unit' => $plan->businessUnit?->name,
                'site' => $plan->site?->name,
                'effective_from' => $plan->effective_from?->toDateString(),
                'next_review_date' => $plan->next_review_date?->toDateString(),
                'review_frequency_months' => $plan->review_frequency_months,
                'is_stale' => $plan->status === 'approved'
                    && $plan->next_review_date !== null
                    && $plan->next_review_date->lt($today),
                // "Approved with no review date" is its own state and is shown
                // as one. Folding it into "current" flatters the number; folding
                // it into "stale" cries wolf.
                'is_undated' => $plan->status === 'approved' && $plan->next_review_date === null,
                // `withCount` aliases are attributes, not declared properties.
                'section_count' => (int) $plan->getAttribute('sections_count'),
                'drifted_section_count' => (int) $plan->getAttribute('drifted_sections_count'),
                'needs_review' => (int) $plan->getAttribute('drifted_sections_count') > 0,
                'offline_bundle_generated_at' => $plan->offline_bundle_generated_at?->toIso8601String(),
                'ai_generated' => (bool) $plan->ai_generated,
                'supersedes_plan_id' => $plan->supersedes_plan_id,
            ])->all(),
            'currency' => $this->plans->currency(),
            // The policy is not offered as a filter either: it is not in the
            // list, so a filter that returned nothing would look broken.
            'types' => array_values(array_map(fn (PlanType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ], array_filter(PlanType::cases(), fn (PlanType $t) => $t !== PlanType::Policy))),
            'templates' => $this->plans->templates(),
            'filters' => [
                'type' => $filters['type'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'scope_note' => $this->scope->describe($user),
            'can' => [
                'manage' => $user?->can('bcms.plan.manage') === true,
                'approve' => $user?->can('bcms.plan.approve') === true,
                'activate' => $user?->can('bcms.plan.activate') === true,
                'export_contacts' => $user?->can('bcms.contact.export') === true,
            ],
        ];
    }
}
