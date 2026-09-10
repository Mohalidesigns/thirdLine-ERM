<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\PlanSectionSource;
use App\Models\Bcms\Plan;
use App\Models\User;
use App\Services\Bcms\Plans\PlanAcknowledgementService;
use App\Services\Bcms\Plans\PlanActivationService;
use App\Services\Bcms\Plans\PlanAiDrafter;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Support\Bcms\AudienceRule;

/**
 * The plan builder and the plan viewer — one presenter, because they are one
 * document seen two ways.
 *
 * A BOUND SECTION SHOWS ITS DATA AND ITS PROVENANCE TOGETHER. The table, the
 * source it came from, the date it was last verified, and whether that source
 * has moved since. A recovery-objectives table with no "last verified" stamp is
 * indistinguishable from one that was typed in 2024, which is the whole problem
 * this module was built to solve.
 *
 * THE VERSION CHAIN IS ALWAYS PRESENT. An approved plan's screen carries a link
 * back to what it superseded, because the second question anybody asks about a
 * plan is what changed.
 */
class PlanBuilderPresenter
{
    public function __construct(
        private PlanAssembler $assembler,
        private PlanAcknowledgementService $acknowledgements,
        private PlanActivationService $activations,
        private PlanAiDrafter $drafter,
    ) {}

    /** @return array<string, mixed> */
    public function present(Plan $plan, ?User $user): array
    {
        $plan->loadMissing([
            'owner:id,name', 'approver:id,name', 'businessUnit:id,name', 'site:id,name',
            'supersedes:id,title,version,status',
        ]);

        $chain = $this->versionChain($plan);

        $today = now()->startOfDay();

        return [
            'plan' => [
                'id' => $plan->getKey(),
                'uuid' => $plan->uuid,
                'title' => $plan->title,
                'plan_type' => $plan->plan_type->value,
                'plan_type_label' => $plan->plan_type->label(),
                'version' => $plan->version,
                'status' => $plan->status,
                'immutable' => $plan->isImmutable(),
                'owner' => $plan->owner?->name,
                'owner_id' => $plan->owner_id,
                'approver' => $plan->approver?->name,
                'approved_at' => $plan->approved_at?->toIso8601String(),
                'business_unit' => $plan->businessUnit?->name,
                'business_unit_id' => $plan->business_unit_id,
                'site' => $plan->site?->name,
                'site_id' => $plan->site_id,
                'effective_from' => $plan->effective_from?->toDateString(),
                'next_review_date' => $plan->next_review_date?->toDateString(),
                'review_frequency_months' => $plan->review_frequency_months,
                'is_stale' => $plan->status === 'approved'
                    && $plan->next_review_date !== null
                    && $plan->next_review_date->lt($today),
                'iso_clause_ref' => $plan->iso_clause_ref,
                'ai_generated' => (bool) $plan->ai_generated,
                'distribution_rule' => $plan->distribution_rule,
                'offline_bundle_generated_at' => $plan->offline_bundle_generated_at?->toIso8601String(),
                'supersedes' => $plan->supersedes === null ? null : [
                    'id' => $plan->supersedes->getKey(),
                    'title' => $plan->supersedes->title,
                    'version' => $plan->supersedes->version,
                ],
            ],
            'sections' => $this->assembler->document($plan),
            // The frozen document is what an approved plan prints; the live
            // render is what it WOULD say today. Showing both on the screen is
            // how a reviewer decides whether a v2 is needed, and it is the only
            // place the two are ever put side by side.
            'live_sections' => $plan->isImmutable() ? $this->assembler->render($plan) : null,
            'version_chain' => $chain,
            'sources' => PlanSectionSource::options(),
            'acknowledgement' => $plan->status === 'approved'
                ? $this->acknowledgements->coverage($plan)
                : null,
            'has_acknowledged' => $user !== null
                && $plan->status === 'approved'
                && $this->acknowledgements->hasAcknowledged($plan, $user),
            'activation' => $this->activations->history($plan),
            'ai' => [
                'available' => $this->drafter->available($plan),
                'reason' => $this->drafter->unavailableReason($plan),
            ],
            'audience_types' => AudienceRule::LEAF_TYPES,
            'can' => [
                'manage' => $user?->can('bcms.plan.manage') === true && ! $plan->isImmutable(),
                'approve' => $user?->can('bcms.plan.approve') === true,
                'activate' => $user?->can('bcms.plan.activate') === true,
                'bundle' => $user?->can('bcms.contact.export') === true,
            ],
        ];
    }

    /**
     * Every version of this plan, newest first.
     *
     * WALKED IN BOTH DIRECTIONS FROM WHERE THE USER IS. `supersedes_plan_id` is
     * a linked list and the user may have opened any link in it — usually the
     * newest, sometimes an archived one an examiner asked for. Walking up to the
     * oldest and down to the newest gives the same chain either way, which a
     * single `where supersedes_plan_id = ?` does not. Bounded, because a chain
     * with a loop in it — which a bad import can produce — would otherwise spin
     * here for ever.
     *
     * @return list<array<string, mixed>>
     */
    private function versionChain(Plan $plan): array
    {
        $seen = [];
        $chain = [];

        $columns = ['id', 'title', 'version', 'status', 'approved_at', 'supersedes_plan_id'];

        $walk = function (?Plan $node) use (&$seen, &$chain, $columns) {
            $guard = 0;

            while ($node !== null && $guard++ < 50) {
                if (isset($seen[$node->getKey()])) {
                    return;
                }

                $seen[$node->getKey()] = true;
                $chain[] = $node;

                $node = Plan::query()
                    ->whereKey($node->supersedes_plan_id)
                    ->first($columns);
            }
        };

        // Down to the newest first, then up through everything it replaced.
        $newest = $plan;
        $guard = 0;

        while ($guard++ < 50) {
            $next = Plan::query()->where('supersedes_plan_id', $newest->getKey())->first($columns);

            if ($next === null) {
                break;
            }

            $newest = $next;
        }

        $walk($newest);

        usort($chain, fn (Plan $a, Plan $b) => $b->getKey() <=> $a->getKey());

        return array_map(fn (Plan $p) => [
            'id' => $p->getKey(),
            'uuid' => $p->uuid,
            'version' => $p->version,
            'status' => $p->status,
            'approved_at' => $p->approved_at?->toDateString(),
            'is_current' => $p->getKey() === $plan->getKey(),
        ], $chain);
    }
}
