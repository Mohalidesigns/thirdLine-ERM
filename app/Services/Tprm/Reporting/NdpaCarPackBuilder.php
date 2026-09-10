<?php

namespace App\Services\Tprm\Reporting;

use App\Enums\Tprm\DocumentExtractor;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use App\Models\Tprm\NthPartyEdge;
use App\Services\Tprm\Extraction\Extractors\DpaExtractor;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The NDPA Compliance Audit Return evidence pack — FR-RPT-03.
 *
 * GAID Art. 10(7)–(10) requires a Compliance Audit Return **not later than 31
 * March each year**, with a late-filing penalty of 50% of the filing fee. This
 * class assembles the third-party half of it: what this institution's
 * processors are, what its agreements with them say, where data goes, which
 * assessments were triggered, what broke, and who was told.
 *
 * IT IS AN EVIDENCE PACK, NOT A RETURN. Nothing here is submitted, and no
 * field is filled in on the institution's behalf. The pack is what a data
 * protection officer takes into the filing — and what an auditor asks to see
 * behind it.
 *
 * EVERY SECTION STATES WHAT IT COULD NOT ANSWER. A processor with no DPA on
 * file appears in the DPA section with twenty absent elements and a reason,
 * not omitted from it; a breach with no NDPC deadline says why there is none.
 * A pack that quietly dropped its gaps would be a pack that reports a clean
 * programme and hides the work.
 *
 * THE ART. 28(3) TRIGGER LIST IS PARTLY DERIVABLE AND PARTLY NOT, AND THE
 * SECTION SAYS WHICH. Financial services, cross-border transfer, sensitive
 * data and large-scale processing come off the engagement record. Profiling,
 * automated decision-making, systematic monitoring and new technologies are
 * not captured at intake by this product, so they are reported as not
 * recorded rather than as absent — the difference between "we checked and it
 * does not apply" and "nobody was asked".
 */
class NdpaCarPackBuilder
{
    use StatesAbsence;

    /** The four Art. 28(3) triggers this product records. */
    private const DERIVABLE_TRIGGERS = [
        'financial_services' => 'Financial services (Art. 28(3), express)',
        'cross_border' => 'Cross-border transfer',
        'sensitive_data' => 'Sensitive or restricted personal data',
        'large_scale' => 'Large-scale processing',
    ];

    /** The four it does not. */
    private const UNRECORDED_TRIGGERS = [
        'Profiling', 'Automated decision-making', 'Systematic monitoring', 'New technologies',
    ];

    /**
     * @return list<array{code: string, title: string, citation: string, coverage: string, note: ?string, headers: list<string>, rows: list<array<int, mixed>>}>
     */
    public function sections(): array
    {
        $processors = $this->processors();

        return [
            $this->processorInventory($processors),
            $this->dpaStatus($processors),
            $this->crossBorderTransfers($processors),
            $this->dpiaRegister($processors),
            $this->breachLog(),
            $this->subProcessorDisclosures($processors),
            $this->technicalAndOrganisationalMeasures($processors),
        ];
    }

    /**
     * Days to the filing deadline — GAID Art. 10(7).
     *
     * THE DEADLINE IS ALWAYS THE NEXT 31 MARCH, INCLUDING ON 31 MARCH ITSELF.
     * A countdown that flipped to next year at midnight on the deadline would
     * read "364 days" to somebody who has not filed and is about to be late,
     * which is the one day it must not be reassuring.
     *
     * @return array{deadline: string, days_remaining: int, filing_year: int, overdue_today: bool}
     */
    public function filingCountdown(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();

        $deadline = CarbonImmutable::create($now->year, 3, 31)->startOfDay();

        if ($deadline->isBefore($now)) {
            $deadline = $deadline->addYear();
        }

        return [
            'deadline' => $deadline->toDateString(),
            'days_remaining' => (int) $now->diffInDays($deadline),
            // The return covers the preceding calendar year.
            'filing_year' => $deadline->year - 1,
            'overdue_today' => $deadline->isSameDay($now),
        ];
    }

    /* ================================================================== */

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function processorInventory(Collection $processors): array
    {
        return [
            'code' => 'CAR-1',
            'title' => 'Processor inventory',
            'citation' => 'NDPA §29; GAID Art. 34',
            'coverage' => self::coverage(true),
            'note' => 'Every engagement recorded as processing personal data, whether or not a DPA is on file.',
            'headers' => [
                'Processor', 'Engagement', 'Service', 'Business unit', 'Data categories',
                'Data subject volume', 'Data at rest', 'Processed in', 'Status', 'Tier',
            ],
            'rows' => $processors->map(fn (Engagement $engagement) => [
                $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                $engagement->reference,
                $engagement->name,
                $this->labelOf($engagement->businessUnit, 'name', 'Not recorded'),
                $this->categories($engagement),
                $engagement->data_subject_volume_band
                    ? ucwords(str_replace('_', ' ', $engagement->data_subject_volume_band))
                    : 'Not recorded',
                $engagement->data_location_at_rest ?: 'Not recorded',
                $engagement->data_location_processing ?: 'Not recorded',
                $engagement->status->label(),
                $engagement->effective_tier?->label() ?? 'Not tiered',
            ])->all(),
        ];
    }

    /**
     * The twenty Art. 34(2)(a)–(t) elements, per processor.
     *
     * READ FROM CONFIRMED EXTRACTIONS ONLY. An unconfirmed machine reading of
     * a DPA is a proposal about a legal document; presenting one in a return
     * would be reporting the model's opinion as the institution's position.
     *
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function dpaStatus(Collection $processors): array
    {
        $verdicts = $this->dpaVerdicts($processors);

        $headers = ['Processor', 'Engagement', 'DPA on file', 'Elements present', 'Elements partial', 'Elements absent'];
        foreach (DpaExtractor::ELEMENTS as $letter => $title) {
            $headers[] = '('.$letter.') '.$title;
        }

        $rows = $processors->map(function (Engagement $engagement) use ($verdicts) {
            $reading = $verdicts[$engagement->getKey()] ?? null;

            $row = [
                $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                $engagement->reference,
                $reading === null ? 'No confirmed DPA reading' : 'Yes',
                $reading['present'] ?? 0,
                $reading['partial'] ?? 0,
                $reading === null ? count(DpaExtractor::ELEMENTS) : ($reading['absent'] ?? 0),
            ];

            foreach (array_keys(DpaExtractor::ELEMENTS) as $letter) {
                $row[] = $reading === null
                    // Not "absent": nobody has read a document. The
                    // distinction is the difference between a gap in an
                    // agreement and a gap in the evidence file.
                    ? 'Not assessed'
                    : ucfirst((string) ($reading['elements'][$letter] ?? 'absent'));
            }

            return $row;
        })->all();

        $withoutDpa = $processors->reject(fn (Engagement $e) => isset($verdicts[$e->getKey()]))->count();

        return [
            'code' => 'CAR-2',
            'title' => 'DPA status per processor',
            'citation' => 'GAID Art. 34(2)(a)–(t)',
            'coverage' => self::coverage($withoutDpa === 0),
            'note' => $withoutDpa === 0
                ? null
                : $withoutDpa.' of '.$processors->count().' processors have no confirmed DPA reading on file. '
                    .'Their elements read "Not assessed" rather than "absent" — nobody has read a document.',
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function crossBorderTransfers(Collection $processors): array
    {
        $transfers = $processors->filter(fn (Engagement $engagement) => (bool) $engagement->cross_border);

        $withoutBasis = $transfers->filter(fn (Engagement $engagement) => in_array(
            $engagement->transfer_basis,
            [null, '', 'none'],
            true
        ));

        return [
            'code' => 'CAR-3',
            'title' => 'Cross-border transfers and their bases',
            'citation' => 'NDPA §41(2), §43',
            'coverage' => self::coverage($withoutBasis->isEmpty()),
            'note' => $withoutBasis->isEmpty()
                ? null
                : $withoutBasis->count().' transfers have no recorded lawful basis. The absence of a basis is '
                    .'the finding — it fires the KO-PII-XB knockout, and it is reported here rather than left blank.',
            'headers' => [
                'Processor', 'Engagement', 'Destination (at rest)', 'Destination (processing)',
                'Lawful basis §41(2)', 'Basis note', 'Data categories', 'Volume band',
            ],
            'rows' => $transfers->map(fn (Engagement $engagement) => [
                $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                $engagement->reference,
                $engagement->data_location_at_rest ?: 'Not recorded',
                $engagement->data_location_processing ?: 'Not recorded',
                $this->transferBasisLabel($engagement->transfer_basis),
                $engagement->transfer_basis_note ?: '',
                $this->categories($engagement),
                $engagement->data_subject_volume_band
                    ? ucwords(str_replace('_', ' ', $engagement->data_subject_volume_band))
                    : 'Not recorded',
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function dpiaRegister(Collection $processors): array
    {
        return [
            'code' => 'CAR-4',
            'title' => 'DPIA register and the triggers that fired',
            'citation' => 'GAID Art. 28(3), §28(9)',
            'coverage' => self::coverage(false),
            'note' => 'Four Art. 28(3) triggers are derivable from the engagement record and are evaluated here: '
                .implode(', ', array_values(self::DERIVABLE_TRIGGERS)).'. Four are not captured at intake by this '
                .'product and are reported as not recorded rather than as absent: '
                .implode(', ', self::UNRECORDED_TRIGGERS).'.',
            'headers' => [
                'Processor', 'Engagement', 'DPIA required', 'DPIA recorded', 'Triggers that fired',
                'Triggers not recorded', 'Assessment due before processing began',
            ],
            'rows' => $processors->map(function (Engagement $engagement) {
                $fired = $this->triggersFor($engagement);

                return [
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->reference,
                    $engagement->dpia_required ? 'Yes' : ($fired === [] ? 'No' : 'Not flagged, but triggers fired'),
                    $engagement->dpia_id ? 'Yes' : 'No DPIA on record',
                    $fired === [] ? 'None derivable' : implode('; ', $fired),
                    implode('; ', self::UNRECORDED_TRIGGERS),
                    // §28(9): a DPIA is done BEFORE processing commences, so a
                    // live engagement with triggers and no DPIA is already late.
                    $engagement->start_date?->toDateString() ?? 'Start date not recorded',
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function breachLog(): array
    {
        $incidents = Incident::query()
            ->with('thirdParty:id,legal_name')
            ->where('personal_data_involved', true)
            ->orderByDesc('reported_to_us_at')
            ->get();

        $missed = $incidents->filter(fn (Incident $incident) => $this->clockMissed($incident));

        return [
            'code' => 'CAR-5',
            'title' => 'Personal-data breach log and clock performance',
            'citation' => 'NDPA §40(1)–(2)',
            'coverage' => self::coverage(true),
            'note' => $missed->isEmpty()
                ? 'Every reportable breach was notified within its window.'
                : $missed->count().' reportable breaches were notified late or not at all.',
            'headers' => [
                'Reference', 'Processor', 'Title', 'Detected', 'Reported to us', 'NDPC reportable',
                'NDPC deadline', 'NDPC notified', 'Clock outcome', 'Data subjects affected',
                'Data subject notification required', 'Data subjects notified',
            ],
            'rows' => $incidents->map(fn (Incident $incident) => [
                $incident->reference,
                $this->labelOf($incident->thirdParty, 'legal_name', 'Not recorded'),
                $incident->title,
                $incident->detected_at?->toDateTimeString() ?? 'Not recorded',
                // §40(2) counts from OUR awareness, which is why this column
                // and not `detected_at` drives the deadline.
                $incident->reported_to_us_at?->toDateTimeString() ?? 'Not recorded',
                $incident->ndpc_reportable ? 'Yes' : 'No',
                $incident->ndpc_deadline_at?->toDateTimeString() ?? 'None set',
                $incident->ndpc_reported_at?->toDateTimeString() ?? 'Not notified',
                $this->clockOutcome($incident),
                $incident->data_subjects_affected ?? 'Not recorded',
                $incident->data_subject_notification_required ? 'Yes' : 'No',
                $incident->data_subject_notified_at?->toDateTimeString() ?? 'Not notified',
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function subProcessorDisclosures(Collection $processors): array
    {
        $providerIds = $processors->pluck('third_party_id')->filter()->unique()->values();

        $edges = NthPartyEdge::query()
            ->with(['parent:id,legal_name', 'child:id,legal_name'])
            ->live()
            ->whereIn('parent_third_party_id', $providerIds)
            ->orderBy('rank')
            ->get();

        $unconfirmed = $edges->reject(fn (NthPartyEdge $edge) => $edge->isConfirmed());

        return [
            'code' => 'CAR-6',
            'title' => 'Sub-processor disclosures',
            'citation' => 'NDPA §44(2); GAID Art. 34(3)',
            'coverage' => self::coverage($unconfirmed->isEmpty()),
            'note' => $unconfirmed->isEmpty()
                ? null
                : $unconfirmed->count().' disclosed sub-processors are unconfirmed. They are listed and marked: '
                    .'a disclosure the institution has received but not yet verified is still a disclosure it holds.',
            'headers' => [
                'Processor', 'Sub-processor', 'Rank', 'Service', 'Data categories',
                'Country of processing', 'How disclosed', 'Disclosed on', 'Confirmation',
            ],
            'rows' => $edges->map(fn (NthPartyEdge $edge) => [
                $this->labelOf($edge->parent, 'legal_name', 'Not recorded'),
                $edge->displayName(),
                $edge->rank,
                $edge->service_description ?: 'Not recorded',
                $this->categoryList($edge->data_categories),
                $edge->country_of_processing ?: 'Not recorded',
                ucwords(str_replace('_', ' ', (string) $edge->disclosure_source?->value)),
                $edge->disclosed_at?->toDateString() ?? 'Not recorded',
                ucfirst((string) $edge->confirmation_status),
            ])->all(),
        ];
    }

    /**
     * Art. 34(2)(j) — the technical and organisational measures.
     *
     * THIS SECTION DOES NOT DESCRIBE MEASURES, IT EVIDENCES THEM. Writing a
     * paragraph about a processor's encryption from its questionnaire answer
     * would be restating a vendor claim in the institution's own voice in a
     * regulatory return. What is reportable is what is on file: the assurance
     * documents held, whether they are current, and the assurance coverage the
     * scoring engine derived from them.
     *
     * @param  Collection<int, Engagement>  $processors
     * @return array<string, mixed>
     */
    private function technicalAndOrganisationalMeasures(Collection $processors): array
    {
        $evidence = $this->assuranceEvidence($processors);

        return [
            'code' => 'CAR-7',
            'title' => 'Technical and organisational measures — evidence held',
            'citation' => 'NDPA §39; GAID Art. 34(2)(j)',
            'coverage' => self::coverage(true),
            'note' => 'What is on file, not what the processor says. Assurance coverage is the share of applicable '
                .'controls evidenced rather than asserted; an empty evidence column against a high coverage figure '
                .'would be a contradiction worth investigating.',
            'headers' => [
                'Processor', 'Engagement', 'Assurance evidence held', 'Current', 'Expired',
                'Assurance coverage', 'Evidence confidence', 'Last validated assessment',
            ],
            'rows' => $processors->map(function (Engagement $engagement) use ($evidence) {
                $held = $evidence[$engagement->getKey()] ?? ['titles' => [], 'current' => 0, 'expired' => 0];

                return [
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->reference,
                    $held['titles'] === [] ? 'None held' : implode('; ', $held['titles']),
                    $held['current'],
                    $held['expired'],
                    $engagement->assurance_coverage !== null
                        ? round((float) $engagement->assurance_coverage * 100).'%'
                        : 'Not scored',
                    $engagement->evidence_confidence !== null
                        ? round((float) $engagement->evidence_confidence * 100).'%'
                        : 'Not scored',
                    $this->lastValidatedAssessment($engagement),
                ];
            })->all(),
        ];
    }

    /* ================================================================== */

    /**
     * @return Collection<int, Engagement>
     */
    private function processors(): Collection
    {
        return Engagement::query()
            ->with(['thirdParty:id,legal_name,registration_number', 'businessUnit:id,name'])
            ->where('processes_personal_data', true)
            ->orderBy('reference')
            ->get();
    }

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<int, array{present: int, partial: int, absent: int, elements: array<string, string>}>
     */
    private function dpaVerdicts(Collection $processors): array
    {
        if ($processors->isEmpty()) {
            return [];
        }

        $engagementIds = $processors->modelKeys();
        $providerIds = $processors->pluck('third_party_id')->filter()->unique()->values()->all();

        $documents = Document::query()
            ->select('id', 'owner_type', 'owner_id')
            ->where('is_superseded', false)
            ->where(function ($query) use ($engagementIds, $providerIds) {
                $query->where(fn ($q) => $q
                    ->where('owner_type', Document::OWNER_ENGAGEMENT)
                    ->whereIn('owner_id', $engagementIds));

                if ($providerIds !== []) {
                    $query->orWhere(fn ($q) => $q
                        ->where('owner_type', Document::OWNER_THIRD_PARTY)
                        ->whereIn('owner_id', $providerIds));
                }
            })
            ->get();

        if ($documents->isEmpty()) {
            return [];
        }

        $extractions = DocumentExtraction::query()
            ->where('extractor', DocumentExtractor::Dpa->value)
            ->where('status', DocumentExtraction::STATUS_CONFIRMED)
            ->whereIn('document_id', $documents->modelKeys())
            ->orderByDesc('confirmed_at')
            ->get()
            ->keyBy('document_id');

        $byOwner = $documents->groupBy(fn (Document $document) => $document->owner_type.':'.$document->owner_id);

        $result = [];

        foreach ($processors as $engagement) {
            $candidates = collect()
                ->merge($byOwner->get(Document::OWNER_ENGAGEMENT.':'.$engagement->getKey(), collect()))
                ->merge($byOwner->get(Document::OWNER_THIRD_PARTY.':'.$engagement->third_party_id, collect()));

            $reading = $candidates
                ->map(fn (Document $document) => $extractions->get($document->getKey()))
                ->filter()
                ->first();

            if ($reading === null) {
                continue;
            }

            $elements = [];
            $counts = ['present' => 0, 'partial' => 0, 'absent' => 0];

            foreach (array_keys(DpaExtractor::ELEMENTS) as $letter) {
                $verdict = (string) ($reading->extracted['elements'][$letter]['verdict'] ?? 'absent');
                $elements[$letter] = $verdict;
                $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
            }

            $result[$engagement->getKey()] = $counts + ['elements' => $elements];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Engagement>  $processors
     * @return array<int, array{titles: list<string>, current: int, expired: int}>
     */
    private function assuranceEvidence(Collection $processors): array
    {
        if ($processors->isEmpty()) {
            return [];
        }

        $engagementIds = $processors->modelKeys();
        $providerIds = $processors->pluck('third_party_id')->filter()->unique()->values()->all();

        $documents = Document::query()
            ->select('tp_documents.id', 'tp_documents.owner_type', 'tp_documents.owner_id', 'tp_documents.title', 'tp_documents.valid_to')
            ->join('tp_document_types', 'tp_document_types.id', '=', 'tp_documents.document_type_id')
            ->where('tp_document_types.is_assurance_evidence', true)
            ->where('tp_documents.is_superseded', false)
            ->where(function ($query) use ($engagementIds, $providerIds) {
                $query->where(fn ($q) => $q
                    ->where('tp_documents.owner_type', Document::OWNER_ENGAGEMENT)
                    ->whereIn('tp_documents.owner_id', $engagementIds));

                if ($providerIds !== []) {
                    $query->orWhere(fn ($q) => $q
                        ->where('tp_documents.owner_type', Document::OWNER_THIRD_PARTY)
                        ->whereIn('tp_documents.owner_id', $providerIds));
                }
            })
            ->get();

        $byOwner = $documents->groupBy(fn (Document $document) => $document->owner_type.':'.$document->owner_id);

        $result = [];

        foreach ($processors as $engagement) {
            $held = collect()
                ->merge($byOwner->get(Document::OWNER_ENGAGEMENT.':'.$engagement->getKey(), collect()))
                ->merge($byOwner->get(Document::OWNER_THIRD_PARTY.':'.$engagement->third_party_id, collect()));

            $result[$engagement->getKey()] = [
                'titles' => $held->pluck('title')->unique()->values()->all(),
                'current' => $held->filter(fn (Document $document) => ! $document->isExpired())->count(),
                'expired' => $held->filter(fn (Document $document) => $document->isExpired())->count(),
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function triggersFor(Engagement $engagement): array
    {
        $fired = [
            // GAID Art. 28(3) names financial services expressly, so for a
            // regulated institution this fires on every processor. That is not
            // a defect in the derivation; it is the rule.
            self::DERIVABLE_TRIGGERS['financial_services'],
        ];

        if ($engagement->cross_border) {
            $fired[] = self::DERIVABLE_TRIGGERS['cross_border'];
        }

        if ($this->handlesSensitiveData($engagement)) {
            $fired[] = self::DERIVABLE_TRIGGERS['sensitive_data'];
        }

        if (in_array($engagement->data_subject_volume_band, ['large', 'very_large', 'over_1m'], true)) {
            $fired[] = self::DERIVABLE_TRIGGERS['large_scale'];
        }

        return $fired;
    }

    private function handlesSensitiveData(Engagement $engagement): bool
    {
        $categories = array_map('strval', (array) ($engagement->data_categories ?? []));

        foreach ($categories as $category) {
            if (in_array($category, ['restricted', 'sensitive', 'special_category', 'biometric', 'health'], true)) {
                return true;
            }
        }

        return false;
    }

    private function clockMissed(Incident $incident): bool
    {
        if (! $incident->ndpc_reportable || $incident->ndpc_deadline_at === null) {
            return false;
        }

        return $incident->ndpc_reported_at === null
            ? $incident->ndpc_deadline_at->isPast()
            : $incident->ndpc_reported_at->isAfter($incident->ndpc_deadline_at);
    }

    private function clockOutcome(Incident $incident): string
    {
        if (! $incident->ndpc_reportable) {
            return 'Not reportable to the NDPC';
        }

        if ($incident->ndpc_deadline_at === null) {
            // Phase 9: an undetermined clock sets no deadline, deliberately.
            return 'No deadline set — assessment undetermined';
        }

        if ($incident->ndpc_reported_at === null) {
            return $incident->ndpc_deadline_at->isPast() ? 'Overdue, not notified' : 'Within window, not yet notified';
        }

        return $incident->ndpc_reported_at->isAfter($incident->ndpc_deadline_at)
            ? 'Notified late'
            : 'Notified within the window';
    }

    private function lastValidatedAssessment(Engagement $engagement): string
    {
        $validated = $engagement->assessments()->whereNotNull('validated_at')->max('validated_at');

        return $validated ? substr((string) $validated, 0, 10) : 'No validated assessment';
    }

    private function categories(Engagement $engagement): string
    {
        return $this->categoryList($engagement->data_categories);
    }

    private function categoryList(mixed $categories): string
    {
        $categories = array_filter(array_map('strval', (array) ($categories ?? [])));

        return $categories === []
            ? 'Not recorded'
            : implode(', ', array_map(fn (string $c) => ucwords(str_replace('_', ' ', $c)), $categories));
    }

    private function transferBasisLabel(?string $basis): string
    {
        return match ($basis) {
            null, '' => 'Not recorded',
            'none' => 'NONE RECORDED — the absence of a basis is the finding',
            'ndpa_41_law' => 'NDPA §41(1) — adequate jurisdiction',
            'bcr' => 'Binding corporate rules',
            'scc' => 'Standard contractual clauses',
            'code_of_conduct' => 'Code of conduct',
            'certification' => 'Certification',
            'derogation_43' => 'NDPA §43 derogation',
            default => ucwords(str_replace('_', ' ', $basis)),
        };
    }

    private static function coverage(bool $complete): string
    {
        return $complete ? DoraRegisterBuilder::COVERAGE_COMPLETE : DoraRegisterBuilder::COVERAGE_PARTIAL;
    }
}
