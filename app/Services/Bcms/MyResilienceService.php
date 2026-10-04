<?php

namespace App\Services\Bcms;

use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Models\Bcms\TrainingRecord;
use App\Models\User;
use App\Services\Bcms\Training\TrainingComplianceService;

/**
 * The employee's own resilience view — `docs/bcms/screens/my-resilience.md`.
 *
 * PLAIN LANGUAGE, NO BCMS VOCABULARY IN THE DATA THIS SERVICE RETURNS is the
 * screen's job, not this service's — but every fact here is the same
 * underlying row the rest of the module reads, never a second copy.
 *
 * NO SELF-SERVICE EDIT PATH FOR CONTACT DETAILS. ADR 0018 §1: "My Emergency
 * Profile" self-service capture is Phase 2D's; this reads `bcms_contacts`
 * read-only.
 */
class MyResilienceService
{
    public function __construct(private readonly TrainingComplianceService $trainingCompliance) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        return [
            'training' => $this->training($user),
            'plans' => $this->plans($user),
            'call_tree_role' => $this->callTreeRole($user),
            'contact' => $this->contact($user),
            'next_exercise' => $this->nextExercise($user),
        ];
    }

    /**
     * ONE ROW PER CURRICULUM THE PERSON IS CURRENTLY ASSIGNED TO, resolved
     * the SAME WAY `training-compliance.md`/`TrainingComplianceService`
     * resolves it — active curricula only (`is_active`, never a curriculum
     * ADR 0021 §3's superseded-code retirement has switched off) and current
     * role-holders (`assignedUsers()`, never a stored enrolment list) — with
     * the person's OWN latest record attached where one exists.
     *
     * ENUMERATION IS OVER ASSIGNMENT, NOT OVER RECORDS
     * (`docs/bcms/screens/my-resilience.md` §2 card 1, and
     * `TrainingComplianceService::complianceRows()`'s own model: a row for
     * every assigned person, with or without a record). A curriculum the
     * person is currently assigned to but has never attended renders "not
     * yet attended" (`completed_at` and `next_due_date` both null — there is
     * no first-attendance-by rule in this schema to compute one from, and
     * inventing a due date would be exactly what `NoFabricatedNumbersTest`
     * exists to catch), not silently absent — a person newly assigned to a
     * mandatory curriculum has an assignment even before they start it, and
     * "no training currently assigned" would be false.
     *
     * A `bcms_training_records` row against a curriculum that has since been
     * retired, or one the person is no longer a role-holder for, is real
     * history but not a current assignment, so it never surfaces here —
     * the defect qa-engineer's gate 1 re-gate found.
     *
     * THE UNIVERSAL-AUDIENCE CURRICULUM (`target_roles = ['*']`, e.g.
     * `BC-AWARE-ALL`) GETS NO CARVE-OUT. `assignedUsers()` resolves `'*'` to
     * every active user, so an all-staff curriculum is unambiguously one
     * everyone is "currently assigned to" per `my-resilience.md` §2 card 1 —
     * and `TrainingComplianceService::complianceRows()`, the register this
     * card mirrors, already renders exactly this row shape (no record →
     * "not yet attended", counted overdue by `summaryTiles()`) for every
     * active user against a universal curriculum with no special case at
     * all. A placeholder here that only ever showed once attended made a
     * never-trained employee on mandatory awareness training invisible on
     * their own resilience page — the opposite of what the card exists to
     * surface. A previous version of this method special-cased `'*'` out on
     * the reasoning that there is no per-person first-attendance date to
     * measure lateness against; that reasoning does not survive reading the
     * screen spec it cites, which draws no such distinction.
     *
     * B10: assignment is checked directly against THIS ONE PERSON
     * (`TrainingComplianceService::isAssignedTo()`) instead of loading every
     * role-holder per curriculum (`assignedUsers()`, which for the
     * universal `'*'` curriculum was every active user in the tenant) and
     * testing collection membership. Training records for every curriculum
     * are fetched in one query, not one query per curriculum.
     *
     * @return list<array<string, mixed>>
     */
    private function training(User $user): array
    {
        $curricula = $this->trainingCompliance->curricula();

        // R5-consistent tiebreaker: `completed_at DESC, id DESC` — the same
        // ordering `TrainingComplianceService::complianceRows()` uses,
        // rather than `completed_at` alone, which leaves a tie between two
        // records on the same day to whatever order the database happens
        // to return them in.
        $latestByCurriculum = TrainingRecord::query()
            ->whereIn('curriculum_id', $curricula->pluck('id'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('curriculum_id')
            ->map(fn ($records) => $records->first());

        $user->loadMissing('roles:id,name');

        $rows = [];

        foreach ($curricula as $curriculum) {
            if (! $this->trainingCompliance->isAssignedTo($curriculum, $user)) {
                continue;
            }

            $latest = $latestByCurriculum->get($curriculum->getKey());

            // A10: `competency_assessed` means "passed" (B3), so a FAILED
            // assessment — a real record with a score, an assessor and a
            // date, just below the pass mark — read as `false`, identical
            // to "not yet assessed". The person cannot tell from this card
            // whether nobody has assessed them yet or they sat the
            // assessment and did not clear the pass mark. `assessed`
            // (an assessment happened, pass or fail) and `failed` (it
            // happened and did not pass) are additive facts alongside the
            // existing key, never a silent rename a caller could miss.
            $wasAssessed = $latest !== null && ($latest->assessor_id !== null || $latest->score !== null);

            $rows[] = [
                'curriculum_name' => $curriculum->name,
                'completed_at' => $latest?->completed_at?->toDateString(),
                'competency_assessed' => (bool) $latest?->competency_assessed,
                'assessed' => $wasAssessed,
                'failed' => $wasAssessed && ! (bool) $latest->competency_assessed,
                'requires_assessment' => (bool) $curriculum->requires_assessment,
                'next_due_date' => $latest?->next_due_date?->toDateString(),
                'overdue' => $latest?->next_due_date !== null && $latest->next_due_date->isPast(),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function plans(User $user): array
    {
        $plans = Plan::query()
            ->where('status', 'approved')
            ->where(fn ($q) => $q->where('business_unit_id', $user->business_unit_id)->orWhereNull('business_unit_id'))
            ->orderByDesc('effective_from')
            ->get();

        $acknowledged = PlanAttestation::query()
            ->whereIn('plan_id', $plans->pluck('id'))
            ->where('attestation_type', 'read')
            ->where('attested_by', $user->getKey())
            ->get()
            ->keyBy('plan_id');

        return $plans->map(fn (Plan $p) => [
            'id' => $p->getKey(),
            'title' => $p->title,
            'version' => $p->version,
            'effective_from' => $p->effective_from?->toDateString(),
            'acknowledged_at' => $acknowledged->get($p->getKey())?->attested_at?->toIso8601String(),
            // Plan carries HasBcmsUuid and route-keys on `uuid` — the URL is
            // built here, not from the numeric `id` above, so the screen
            // never has to know which key a route binds on
            // (ModuleActionUrlRouteKeyTest's own rule).
            'show_url' => route('bcms.plans.show', $p),
            'acknowledge_url' => route('bcms.plans.acknowledge', $p),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function callTreeRole(User $user): ?array
    {
        $contactId = Contact::query()->where('user_id', $user->getKey())->value('id');

        $node = CallTreeNode::query()
            ->where(fn ($q) => $q->where('user_id', $user->getKey())->when($contactId, fn ($q2) => $q2->orWhere('contact_id', $contactId)))
            ->with('callTree:id,name')
            ->first();

        if ($node === null) {
            return null;
        }

        return [
            'tree_name' => $node->callTree?->name,
            'tier' => $node->tier,
            'role_label' => $node->role_label,
            'is_must_reach' => (bool) $node->is_must_reach,
            'has_deputy' => $node->deputy_user_id !== null || $node->deputy_contact_id !== null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function contact(User $user): ?array
    {
        $contact = Contact::query()->where('user_id', $user->getKey())->first();

        if ($contact === null) {
            return null;
        }

        return [
            'mobile_primary' => $contact->mobile_primary,
            'whatsapp' => $contact->whatsapp,
            'preferred_language' => $contact->preferred_language,
            'consent_status' => $contact->consent_status->value,
            'verification_status' => $contact->verification_status->value,
            'last_verified_at' => $contact->last_verified_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function nextExercise(User $user): ?array
    {
        $contactId = Contact::query()->where('user_id', $user->getKey())->value('id');

        $participant = ExerciseParticipant::query()
            ->where(fn ($q) => $q->where('user_id', $user->getKey())->when($contactId, fn ($q2) => $q2->orWhere('contact_id', $contactId)))
            ->whereHas('occurrence', fn ($q) => $q->where('scheduled_date', '>=', now()->toDateString()))
            ->with('occurrence:id,scheduled_date')
            ->orderBy('occurrence_id')
            ->get()
            ->sortBy(fn ($p) => $p->occurrence?->scheduled_date)
            ->first();

        if ($participant === null) {
            return null;
        }

        return [
            'occurrence_id' => $participant->occurrence_id,
            'scheduled_date' => $participant->occurrence?->scheduled_date?->toDateString(),
            'role' => $participant->role,
        ];
    }
}
