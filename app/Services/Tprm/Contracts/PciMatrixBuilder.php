<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\PciResponsibility;
use Illuminate\Support\Facades\DB;

/**
 * The PCI DSS 12.8.5 responsibility matrix — FR-CTR-08.
 *
 * "Information is maintained about which PCI DSS requirements are managed by
 * each TPSP, which are managed by the entity, and any that are shared." A QSA
 * asks for this at every assessment, and almost nobody has it — which means it
 * gets assembled in a spreadsheet the week before, from memory.
 *
 * A ROW NOBODY HAS CONFIRMED IS NOT AN ANSWER. Pre-population from a vendor's
 * CAIQ SSRM answers is a proposal: it is the VENDOR's opinion of who is
 * responsible, and a vendor with a generous view of that opinion can quietly
 * assign duties to us. `last_confirmed_at` being null is what says so, and the
 * export marks those rows rather than presenting them as agreed.
 *
 * EVERY REQUIREMENT GETS A ROW, INCLUDING THE ONES THAT DO NOT APPLY. A matrix
 * with twelve of nineteen rows is a matrix a QSA has to ask about seven times.
 * `na` with a note is an answer; a missing row is not.
 */
class PciMatrixBuilder
{
    /**
     * The requirements the matrix covers — the twelve, plus 12.8.x and 12.9.x
     * because those are the ones about third parties specifically and a
     * third-party matrix that omitted them would be missing its own subject.
     */
    public const REQUIREMENTS = [
        '1' => 'Install and maintain network security controls',
        '2' => 'Apply secure configurations to all system components',
        '3' => 'Protect stored account data',
        '4' => 'Protect cardholder data with strong cryptography during transmission over open, public networks',
        '5' => 'Protect all systems and networks from malicious software',
        '6' => 'Develop and maintain secure systems and software',
        '7' => 'Restrict access to system components and cardholder data by business need to know',
        '8' => 'Identify users and authenticate access to system components',
        '9' => 'Restrict physical access to cardholder data',
        '10' => 'Log and monitor all access to system components and cardholder data',
        '11' => 'Test security of systems and networks regularly',
        '12' => 'Support information security with organisational policies and programmes',
        '12.8.1' => 'A list of all third-party service providers is maintained',
        '12.8.2' => 'Written agreements including the TPSP\'s acknowledgement of responsibility',
        '12.8.3' => 'A process for engaging TPSPs, including due diligence before engagement',
        '12.8.4' => 'A programme to monitor TPSPs\' PCI DSS compliance status at least every 12 months',
        '12.8.5' => 'Information about which requirements are managed by each TPSP, the entity, or shared',
        '12.9.1' => 'TPSPs acknowledge in writing their responsibility for account data security',
        '12.9.2' => 'TPSPs support customers\' requests for compliance information',
    ];

    /**
     * Create any missing rows for an engagement, leaving existing ones alone.
     *
     * New rows start `na` with no confirmation. That is deliberately the
     * conservative default in the OTHER direction from most: an unconfirmed
     * row must not read as "the provider handles this", because the whole
     * failure mode of a PCI matrix is a duty each party believes the other
     * has.
     *
     * @return array{created: int, existing: int}
     */
    public function ensureRows(Engagement $engagement): array
    {
        $existing = PciResponsibility::query()
            ->where('engagement_id', $engagement->getKey())
            ->pluck('pci_requirement')
            ->flip();

        $created = 0;

        DB::transaction(function () use ($engagement, $existing, &$created) {
            foreach (array_keys(self::REQUIREMENTS) as $requirement) {
                if ($existing->has($requirement)) {
                    continue;
                }

                PciResponsibility::create([
                    'organization_id' => $engagement->organization_id,
                    'engagement_id' => $engagement->getKey(),
                    'pci_requirement' => $requirement,
                    'responsibility' => PciResponsibility::NOT_APPLICABLE,
                    'source' => PciResponsibility::SOURCE_MANUAL,
                ]);

                $created++;
            }
        });

        return ['created' => $created, 'existing' => $existing->count()];
    }

    /**
     * Pre-populate from a vendor's assessment answers where they carry SSRM
     * ownership — FR-CTR-08's "pre-populated from CAIQ SSRM ownership answers
     * where an assessment supplies them".
     *
     * ONLY UNCONFIRMED ROWS ARE TOUCHED. A row a person has agreed is the
     * agreed position, and a later assessment must not overwrite it with the
     * vendor's own view — that would let a vendor reassign a duty by answering
     * a questionnaire differently next year.
     *
     * @return array{proposed: int, skipped_confirmed: int, source_assessment: int|null}
     */
    public function prepopulateFromAssessment(Engagement $engagement): array
    {
        $assessment = Assessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->whereIn('status', ['validated', 'scored', 'closed'])
            ->orderByDesc('validated_at')
            ->orderByDesc('id')
            ->first();

        if ($assessment === null) {
            return ['proposed' => 0, 'skipped_confirmed' => 0, 'source_assessment' => null];
        }

        $ownership = $this->ownershipFromResponses($assessment);

        if ($ownership === []) {
            return ['proposed' => 0, 'skipped_confirmed' => 0, 'source_assessment' => $assessment->getKey()];
        }

        $proposed = 0;
        $skipped = 0;

        foreach ($ownership as $requirement => $responsibility) {
            $row = PciResponsibility::query()
                ->where('engagement_id', $engagement->getKey())
                ->where('pci_requirement', $requirement)
                ->first();

            if ($row === null) {
                continue;
            }

            if ($row->isConfirmed()) {
                $skipped++;

                continue;
            }

            $row->forceFill([
                'responsibility' => $responsibility,
                'source' => PciResponsibility::SOURCE_CAIQ,
                // Explicitly left unconfirmed. This is the vendor's opinion
                // until somebody here agrees with it.
                'last_confirmed_at' => null,
                'confirmed_by' => null,
            ])->save();

            $proposed++;
        }

        return [
            'proposed' => $proposed,
            'skipped_confirmed' => $skipped,
            'source_assessment' => $assessment->getKey(),
        ];
    }

    /**
     * Confirm a row — the act that turns a proposal into the agreed position.
     */
    public function confirm(PciResponsibility $row, string $responsibility, ?string $notes, ?int $userId): PciResponsibility
    {
        $row->forceFill([
            'responsibility' => $responsibility,
            'notes' => $notes,
            'last_confirmed_at' => now(),
            'confirmed_by' => $userId,
        ])->save();

        return $row->refresh();
    }

    /**
     * The matrix as a QSA reads it.
     *
     * @return array<string, mixed>
     */
    public function matrix(Engagement $engagement): array
    {
        $rows = PciResponsibility::query()
            ->where('engagement_id', $engagement->getKey())
            ->with('confirmer:id,name')
            ->get()
            ->keyBy('pci_requirement');

        $entries = [];

        foreach (self::REQUIREMENTS as $requirement => $description) {
            $row = $rows->get($requirement);

            $entries[] = [
                'requirement' => $requirement,
                'description' => $description,
                'responsibility' => $row->responsibility ?? PciResponsibility::NOT_APPLICABLE,
                'responsibility_label' => $row?->responsibilityLabel() ?? 'Not applicable',
                'notes' => $row?->notes,
                'source' => $row->source ?? PciResponsibility::SOURCE_MANUAL,
                'confirmed' => $row?->isConfirmed() ?? false,
                'confirmed_by' => $row?->confirmer?->name,
                'confirmed_at' => $row?->last_confirmed_at?->toDateString(),
                'id' => $row?->getKey(),
            ];
        }

        $unconfirmed = collect($entries)->where('confirmed', false)->count();

        return [
            'engagement' => [
                'id' => $engagement->getKey(),
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'pci_in_scope' => (bool) $engagement->pci_in_scope,
            ],
            'rows' => $entries,
            'total' => count($entries),
            'confirmed' => count($entries) - $unconfirmed,
            'unconfirmed' => $unconfirmed,
            // Said plainly on the export. A matrix presented as agreed when a
            // third of it is the vendor's unreviewed opinion is worse than no
            // matrix, because a QSA relies on it.
            'export_ready' => $unconfirmed === 0,
        ];
    }

    /**
     * SSRM ownership read off assessment answers.
     *
     * The mapping is by the question's PCI control map, so a tenant that
     * writes its own PCI questions gets the same behaviour without this class
     * knowing their codes. Answers that do not name one of the four
     * responsibilities are ignored rather than guessed at.
     *
     * @return array<string, string>
     */
    private function ownershipFromResponses(Assessment $assessment): array
    {
        $responses = AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->whereHas('question.controlMaps', fn ($q) => $q->where('framework', 'pci_dss_401'))
            ->with(['question.controlMaps' => fn ($q) => $q->where('framework', 'pci_dss_401')])
            ->get();

        $ownership = [];

        foreach ($responses as $response) {
            $value = $response->value;
            $stated = is_array($value) ? ($value['responsibility'] ?? null) : $value;

            if (! is_string($stated)) {
                continue;
            }

            $normalised = match (strtolower(trim($stated))) {
                'tpsp', 'provider', 'service provider' => PciResponsibility::TPSP,
                'entity', 'customer', 'us' => PciResponsibility::ENTITY,
                'shared', 'both' => PciResponsibility::SHARED,
                'na', 'n/a', 'not applicable' => PciResponsibility::NOT_APPLICABLE,
                default => null,
            };

            if ($normalised === null) {
                continue;
            }

            foreach ($response->question->controlMaps as $map) {
                if (array_key_exists($map->control_id, self::REQUIREMENTS)) {
                    $ownership[$map->control_id] = $normalised;
                }
            }
        }

        return $ownership;
    }
}
