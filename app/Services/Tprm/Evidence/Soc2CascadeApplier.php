<?php

namespace App\Services\Tprm\Evidence;

use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Document;
use App\Models\Tprm\Obligation;
use App\Models\Tprm\Soc2Cuec;
use App\Models\Tprm\Soc2Detail;
use Illuminate\Support\Facades\DB;

/**
 * The SECOND confirmation — "yes, apply that to our register".
 *
 * `Soc2Cascade` proposes; this applies, and only what a human selected. The
 * `$selected` argument is not a convenience: an applier that took the whole
 * proposal set would make the second confirmation a single button, and a
 * reviewer who disagrees with one of twelve proposals would have to accept all
 * twelve or none.
 *
 * WHAT THIS PHASE CAN ACTUALLY APPLY, STATED PLAINLY.
 *
 *   Pre-answers are applied: `tp_assessment_responses` exists and the answers
 *   are written with their citation and assurance level.
 *
 *   CUEC ownership is applied, and as of Phase 4 the CUEC also becomes a real
 *   `tp_obligations` row owed by US. That is the whole point of reading a
 *   report's complementary user entity controls: the auditor assumed we
 *   perform these, nobody here agreed to them, and until they are on a
 *   register with an owner and a date they are duties the institution does not
 *   know it has.
 *
 *   Findings and nth-party edges are NOT applied here, and that is the phase
 *   prompt's own instruction: the edge object arrives in Phase 7 and the
 *   proposals persist as `tp_soc2_subservice_orgs` rows until it does. The
 *   proposals are returned so the screen can show them as pending rather than
 *   silently dropping them — a proposal that vanishes on confirmation is worse
 *   than one that says it is waiting.
 */
class Soc2CascadeApplier
{
    public function __construct(private readonly Soc2Cascade $cascade) {}

    /**
     * Apply the selected proposals.
     *
     * @param  array{answers?: list<int>, cuecs?: array<int, array{owner_id?: int|null, control_id?: int|null}>}  $selected
     * @return array<string, mixed> what was applied and what is still waiting
     */
    public function apply(Soc2Detail $soc2, array $selected, ?int $userId = null): array
    {
        $proposals = $this->cascade->propose($soc2);

        return DB::transaction(function () use ($soc2, $proposals, $selected, $userId) {
            $answers = $this->applyAnswers($proposals, $selected['answers'] ?? [], $userId);
            $soc2->loadMissing('document');
            $cuecs = $this->applyCuecOwners($selected['cuecs'] ?? [], $soc2);

            return [
                'answers_applied' => $answers,
                'cuecs_assigned' => $cuecs['assigned'],
                'obligations_created' => $cuecs['obligations'],
                // Not applied, and named rather than dropped.
                'findings_pending' => count($proposals->findings),
                'edges_pending' => count($proposals->nthPartyEdges),
                'bridge_letter_cap' => $proposals->bridgeLetterCap,
            ];
        });
    }

    /**
     * @param  list<int>  $responseIds
     */
    private function applyAnswers(Soc2Proposals $proposals, array $responseIds, ?int $userId): int
    {
        if ($responseIds === []) {
            return 0;
        }

        $wanted = array_flip($responseIds);
        $applied = 0;

        foreach ($proposals->answers as $answer) {
            if (! isset($wanted[$answer['response_id']])) {
                continue;
            }

            $response = AssessmentResponse::query()->find($answer['response_id']);

            // Re-checked at apply time, not trusted from the proposal. Between
            // the proposal being generated and a reviewer clicking confirm,
            // the vendor may have answered the question themselves — and their
            // answer wins, because it is theirs.
            // `isAnswered()` rather than a string comparison: `compliance` is
            // an enum cast, so `$response->compliance !== 'unanswered'` is
            // always true and would silently skip every proposal — a screen
            // that reports success and saves nothing.
            if ($response === null || $response->isAnswered()) {
                continue;
            }

            $response->forceFill([
                'compliance' => $answer['proposed_compliance'],
                'assurance_level' => $answer['proposed_assurance_level'],
                'is_auto_answered' => true,
                'auto_answer_source' => [
                    'source' => 'soc2_cascade',
                    'document_id' => $answer['document_id'],
                    'tsc_criterion' => $answer['tsc_criterion'],
                    'citation' => $answer['citation'],
                    'confirmed_by' => $userId,
                    'confirmed_at' => now()->toIso8601String(),
                ],
            ])->save();

            $applied++;
        }

        return $applied;
    }

    /**
     * Assign owners to the complementary user entity controls, and put each on
     * the obligation register as a duty owed by us.
     *
     * `next_due_at` is a year out because a CUEC is attested each time a new
     * SOC 2 is relied upon, and these reports are annual. An unassigned CUEC
     * keeps no due date at all: a deadline nobody owns produces an overdue
     * item nobody can action, which is how a register loses its credibility.
     *
     * @param  array<int, array{owner_id?: int|null, control_id?: int|null}>  $assignments
     * @return array{assigned: int, obligations: int}
     */
    private function applyCuecOwners(array $assignments, Soc2Detail $soc2): array
    {
        $assigned = 0;
        $obligations = 0;

        $engagementId = $soc2->document?->owner_type === Document::OWNER_ENGAGEMENT
            ? (int) $soc2->document->owner_id
            : null;

        foreach ($assignments as $cuecId => $assignment) {
            $cuec = Soc2Cuec::query()->find($cuecId);

            if ($cuec === null) {
                continue;
            }

            $ownerId = $assignment['owner_id'] ?? null;

            $cuec->forceFill(array_filter([
                'internal_owner_id' => $ownerId,
                'internal_control_id' => $assignment['control_id'] ?? null,
                'next_due_at' => $ownerId !== null ? now()->addYear()->toDateString() : null,
            ], fn ($value) => $value !== null))->save();

            $assigned++;

            if ($engagementId !== null && $this->recordObligation($cuec, $soc2, $engagementId, $ownerId)) {
                $obligations++;
            }
        }

        return ['assigned' => $assigned, 'obligations' => $obligations];
    }

    /**
     * A CUEC as an obligation on the register.
     *
     * `source` is `assessment` rather than `contract`, because nobody
     * negotiated this: it is a duty an auditor assumed the customer performs.
     * Matched on the CUEC reference so re-confirming a corrected extraction
     * does not duplicate the duty or reset the evidence behind it.
     */
    private function recordObligation(Soc2Cuec $cuec, Soc2Detail $soc2, int $engagementId, ?int $ownerId): bool
    {
        $reference = 'SOC 2 '.($cuec->cuec_reference ?? 'CUEC');

        $exists = Obligation::query()
            ->where('engagement_id', $engagementId)
            ->where('source', 'assessment')
            ->where('source_reference', $reference)
            ->exists();

        if ($exists) {
            return false;
        }

        Obligation::create([
            'organization_id' => $cuec->organization_id,
            'engagement_id' => $engagementId,
            'source' => 'assessment',
            'source_reference' => $reference,
            'title' => 'CUEC: '.\Illuminate\Support\Str::limit($cuec->description, 180),
            'description' => $cuec->description,
            // Owed by US. That is the entire reason this table is read.
            'obligor' => Obligation::OBLIGOR_ENTITY,
            'owner_id' => $ownerId,
            'frequency' => 'annual',
            'due_date' => now()->addYear()->toDateString(),
            'next_due_date' => now()->addYear()->toDateString(),
            'evidence_required' => true,
            'citation' => 'Complementary user entity controls, SOC 2 report '
                .($soc2->period_end?->toDateString() ?? ''),
        ]);

        return true;
    }
}
