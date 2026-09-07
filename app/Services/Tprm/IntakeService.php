<?php

namespace App\Services\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Events\Tprm\ProhibitedOutsourcingAttempted;
use App\Exceptions\Tprm\BlockingClauseException;
use App\Exceptions\Tprm\ProhibitedOutsourcingException;
use App\Services\Tprm\Contracts\ActivationGuard;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\TierPolicy;
use App\Services\Tprm\Scoring\TieringOutcome;
use App\Services\Tprm\Scoring\TieringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intake: raising an engagement, tiering it, and routing it for approval.
 *
 * THE PROHIBITED-OUTSOURCING GUARDRAIL IS ENFORCED HERE, INSIDE THE
 * TRANSACTION, and not in a Form Request. AC-01 requires that no engagement
 * row is created — so the check has to sit on the write path itself, where
 * every caller reaches it, rather than in front of one HTTP route. A validator
 * would be bypassed by the bulk importer, by the API, and by any future
 * "duplicate this engagement" button.
 */
class IntakeService
{
    public function __construct(
        private readonly TieringService $tiering,
        private readonly DuplicateServiceDetector $duplicates,
    ) {}

    /**
     * Raise an intake and compute its tier.
     *
     * @param  array<string, mixed>  $attributes  engagement attributes
     * @param  list<int>  $businessFunctionIds
     * @param  array<string, mixed>  $answers  Appendix A answers
     *
     * @throws ProhibitedOutsourcingException
     */
    public function submit(
        array $attributes,
        array $businessFunctionIds,
        array $answers,
        ?int $userId = null,
    ): IntakeResult {
        $functions = BusinessFunction::query()->whereIn('id', $businessFunctionIds)->get();

        $this->guardProhibitedOutsourcing($functions, $attributes, $answers, $userId);

        /** @var Engagement $engagement */
        $engagement = DB::transaction(function () use ($attributes, $functions, $userId) {
            $engagement = Engagement::create($attributes + [
                'reference' => $this->nextReference(),
                'status' => EngagementStatus::Draft->value,
                'created_by' => $userId,
            ]);

            foreach ($functions as $function) {
                $engagement->businessFunctions()->attach($function->id, [
                    'organization_id' => $engagement->organization_id,
                    'dependency_level' => 'primary',
                    'reliance_level' => 'high',
                    'created_by' => $userId,
                ]);
            }

            return $engagement->fresh(['businessFunctions', 'thirdParty']);
        });

        $outcome = $this->tiering->tier($engagement, $answers, $userId);

        // FR-INT-05: surface existing engagements delivering a similar
        // service. Advisory, never blocking — the cheapest concentration
        // control there is, and one a hard block would make people route
        // around.
        $similar = $this->duplicates->similarTo($engagement);

        $this->transition($engagement, EngagementStatus::IntakeSubmitted, $userId);

        return new IntakeResult(
            engagement: $engagement->refresh(),
            outcome: $outcome,
            similarEngagements: $similar,
        );
    }

    /**
     * Compute a tier WITHOUT persisting anything — the intake form's live
     * preview (FR-INT-02: the tier is displayed to the requester before
     * submission).
     *
     * @param  list<int>  $businessFunctionIds
     * @param  array<string, mixed>  $answers
     */
    public function previewTier(array $attributes, array $businessFunctionIds, array $answers): TieringOutcome
    {
        // An unsaved model, so the preview cannot leave a row behind. It
        // carries the attributes the knockout rules read, and the functions
        // are attached in memory rather than through the pivot.
        $engagement = new Engagement($attributes);
        $engagement->setRelation(
            'businessFunctions',
            BusinessFunction::query()->whereIn('id', $businessFunctionIds)->get()
        );
        $engagement->setRelation('thirdParty', null);

        return $this->tiering->preview($engagement, $answers);
    }

    /**
     * AC-01. Blocks, cites, records — and creates nothing.
     *
     * @param  Collection<int, BusinessFunction>  $functions
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $answers
     *
     * @throws ProhibitedOutsourcingException
     */
    private function guardProhibitedOutsourcing(
        Collection $functions,
        array $attributes,
        array $answers,
        ?int $userId,
    ): void {
        $prohibited = $functions->filter(fn (BusinessFunction $f) => (bool) $f->is_prohibited_outsourcing);

        if ($prohibited->isEmpty()) {
            return;
        }

        $exception = new ProhibitedOutsourcingException($prohibited);

        $organizationId = (int) ($attributes['organization_id'] ?? $functions->first()?->organization_id);

        // Audited BEFORE the throw, and written directly rather than through
        // a model observer, because there is no model — the whole point of
        // AC-01 is that nothing was created to hang an audit row off.
        AuditLog::create([
            'organization_id' => $organizationId,
            'auditable_type' => Engagement::class,
            'auditable_id' => 0,
            'event' => 'prohibited_outsourcing_attempted',
            'actor_type' => $userId !== null ? 'user' : 'system',
            'actor_id' => $userId,
            'before' => null,
            'after' => [
                'functions' => $exception->details(),
                'proposed_engagement' => $attributes,
                'answers' => $answers,
            ],
        ]);

        ProhibitedOutsourcingAttempted::dispatch(
            $organizationId,
            null,
            $exception->details(),
            $attributes,
            null,
        );

        throw $exception;
    }

    /**
     * Move an engagement to a new status, refusing a transition the lifecycle
     * does not allow.
     *
     * THE BLOCKING-CLAUSE GATE SITS HERE (FR-CTR-05, AC-06), not in a Form
     * Request, for the reason the prohibited-outsourcing guard sits in this
     * file: a validator guards one HTTP route, and the importer, the API and
     * any future "duplicate this engagement" button all reach the transition
     * without passing it.
     *
     * It is a THROW rather than a `false`, because `false` here already means
     * "the lifecycle does not allow that move" — a caller that treated the two
     * alike would tell a user their engagement was in the wrong status when
     * the truth is that their contract has no audit-rights clause.
     *
     * @throws \App\Exceptions\Tprm\BlockingClauseException
     */
    public function transition(Engagement $engagement, EngagementStatus $target, ?int $userId = null): bool
    {
        $current = $engagement->status;

        if (! $current->canTransitionTo($target)) {
            return false;
        }

        if ($target === EngagementStatus::Active) {
            $verdict = app(ActivationGuard::class)->check($engagement);

            if (! $verdict->allowed) {
                throw new BlockingClauseException($verdict);
            }
        }

        $engagement->forceFill(['status' => $target->value, 'updated_by' => $userId])->save();

        return true;
    }

    /**
     * Approve an intake.
     *
     * FR-INT-08: an engagement supporting a critical or important function
     * cannot pass `intake_approved` without a named executive sponsor. The
     * check is here rather than in a Form Request because the sponsor is a
     * property of the engagement, not of the approval request, and the rule
     * has to hold for an API approval too.
     *
     * @return array{approved: bool, reason: string|null}
     */
    public function approve(Engagement $engagement, ?int $userId = null): array
    {
        if ($engagement->supports_critical_function && $engagement->executive_sponsor_id === null) {
            return [
                'approved' => false,
                'reason' => 'This engagement supports a critical or important business function and needs a named '
                    .'executive sponsor before it can be approved (FR-INT-08).',
            ];
        }

        if (! $this->transition($engagement, EngagementStatus::IntakeApproved, $userId)) {
            return ['approved' => false, 'reason' => 'This intake is not awaiting approval.'];
        }

        return ['approved' => true, 'reason' => null];
    }

    /**
     * Reject an intake with a mandatory reason code.
     *
     * FR-INT-07: a rejected intake is RETAINED, not deleted — it is
     * supervisory evidence that the institution considered and declined an
     * arrangement. The engagement returns to draft carrying its reason.
     */
    public function reject(Engagement $engagement, string $reasonCode, string $rationale, ?int $userId = null): bool
    {
        if (! $this->transition($engagement, EngagementStatus::Draft, $userId)) {
            return false;
        }

        $engagement->forceFill([
            'termination_reason' => $reasonCode,
        ])->save();

        $engagement->writeAuditRow('intake_rejected', null, [
            'reason_code' => $reasonCode,
            'rationale' => $rationale,
        ]);

        return true;
    }

    /**
     * The approval chain for an engagement's tier, from its tier policy
     * (FR-INT-04).
     *
     * @return list<string>
     */
    public function approvalChainFor(Engagement $engagement): array
    {
        $tier = $engagement->effectiveTier();

        if ($tier === null) {
            return [];
        }

        $policy = TierPolicy::query()->where('tier', $tier->value)->first();

        if ($policy === null) {
            return [];
        }

        return array_values((array) ($policy->approval_chain ?? []));
    }

    /**
     * `ENG-{year}-{seq}`, sequential within the tenant and the year.
     *
     * Derived from the highest existing reference rather than from a count,
     * because a deleted engagement must not cause the next one to reuse its
     * number — a reference that has appeared on a board paper cannot be
     * handed to a different vendor.
     */
    private function nextReference(): string
    {
        $year = now()->year;
        $prefix = "ENG-{$year}-";

        $highest = Engagement::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $highest === null ? 1 : ((int) Str::afterLast($highest, '-')) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
