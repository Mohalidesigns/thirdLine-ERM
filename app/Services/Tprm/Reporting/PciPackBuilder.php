<?php

namespace App\Services\Tprm\Reporting;

use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\DueDiligenceChecklist;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\PciResponsibility;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;
use Illuminate\Database\Eloquent\Collection;

/**
 * The PCI DSS v4.0.1 requirement 12.8 pack — FR-RPT-04.
 *
 * A QSA asks for these five things at every assessment and almost nobody has
 * them standing: the list of third-party service providers with access to
 * cardholder data (12.8.1), the written agreements in which those providers
 * acknowledge their responsibility for it (12.8.2), the due diligence done
 * before engaging them (12.8.3), a monitoring log of their compliance status
 * (12.8.4), and the responsibility matrices (12.8.5).
 *
 * 12.8.4's TWELVE-MONTH TEST IS THE ONE THAT FAILS SILENTLY. The requirement
 * is that compliance status is monitored **at least annually**, and an
 * Attestation of Compliance is a point-in-time document. An AoC issued
 * fourteen months ago is not "on file" in any sense a QSA accepts, and a pack
 * that listed it without its age would report a compliant programme. Every row
 * carries the age and the verdict.
 *
 * THE PACK IS SCOPED BY `pci_in_scope`, WHICH IS A DECISION SOMEBODY MADE. An
 * engagement nobody has scoped is not in this pack and is not silently
 * excluded either — the summary counts the personal-data-processing
 * engagements with no PCI scoping decision on record, because "we have four
 * TPSPs" is only true if somebody looked at the other two hundred.
 */
class PciPackBuilder
{
    use StatesAbsence;

    /**
     * The requirement 12.8.2 clause in the shipped library. A tenant that
     * renamed or removed it gets "clause not in library" rather than a silent
     * pass — the absence of the check is not evidence of the term.
     */
    private const AGREEMENT_CLAUSE = 'PCI-12.8.2';

    private const MATRIX_CLAUSE = 'PCI-12.8.5';

    /** PCI DSS 12.8.4 requires monitoring at least annually. */
    private const CURRENCY_MONTHS = 12;

    public function __construct(private readonly ClauseResolver $clauses) {}

    /**
     * @return list<array{code: string, title: string, citation: string, coverage: string, note: ?string, headers: list<string>, rows: list<array<int, mixed>>}>
     */
    public function sections(): array
    {
        $tpsps = $this->tpsps();

        return [
            $this->serviceProviderList($tpsps),
            $this->agreementStatus($tpsps),
            $this->dueDiligenceEvidence($tpsps),
            $this->monitoringLog($tpsps),
            $this->responsibilityMatrices($tpsps),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, int>
     */
    public function summary(Collection $tpsps): array
    {
        $aoc = $this->attestations($tpsps);

        return [
            'tpsps' => $tpsps->count(),
            'agreements_missing' => $tpsps->filter(
                fn (Engagement $engagement) => ! $this->agreementIsAcknowledged($engagement)
            )->count(),
            'aoc_missing' => $tpsps->filter(
                fn (Engagement $engagement) => ! isset($aoc[$engagement->getKey()])
            )->count(),
            'aoc_stale' => $tpsps->filter(function (Engagement $engagement) use ($aoc) {
                $reading = $aoc[$engagement->getKey()] ?? null;

                return $reading !== null && $reading['months'] !== null && $reading['months'] > self::CURRENCY_MONTHS;
            })->count(),
            'matrices_unconfirmed' => $tpsps->filter(
                fn (Engagement $engagement) => $this->matrixCounts($engagement)['unconfirmed'] > 0
            )->count(),
            // Not a PCI number. It is the question behind the first one: how
            // many engagements nobody has scoped for PCI at all.
            'unscoped_personal_data_engagements' => Engagement::query()
                ->where('processes_personal_data', true)
                ->where('pci_in_scope', false)
                ->count(),
        ];
    }

    /* ================================================================== */

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, mixed>
     */
    private function serviceProviderList(Collection $tpsps): array
    {
        return [
            'code' => '12.8.1',
            'title' => 'Third-party service providers with access to cardholder data',
            'citation' => 'PCI DSS v4.0.1 requirement 12.8.1',
            'coverage' => DoraRegisterBuilder::COVERAGE_COMPLETE,
            'note' => 'Scoped by the PCI decision recorded on each engagement. An engagement nobody has scoped '
                .'is not on this list; the pack summary counts those separately.',
            'headers' => [
                'Service provider', 'Engagement', 'Service description', 'Business unit',
                'Services provided', 'Status', 'Tier', 'Residual band', 'Relationship owner',
            ],
            'rows' => $tpsps->map(fn (Engagement $engagement) => [
                $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                $engagement->reference,
                $engagement->service_description ?: $engagement->name,
                $this->labelOf($engagement->businessUnit, 'name', 'Not recorded'),
                $engagement->engagement_type?->label() ?? 'Not recorded',
                $engagement->status->label(),
                $engagement->effective_tier?->label() ?? 'Not tiered',
                $engagement->residual_band?->label() ?? 'Not scored',
                $this->labelOf($engagement->relationshipOwner, 'name', 'Not assigned'),
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, mixed>
     */
    private function agreementStatus(Collection $tpsps): array
    {
        $rows = $tpsps->map(function (Engagement $engagement) {
            $contract = $this->executedContract($engagement);

            return [
                $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                $engagement->reference,
                $this->labelOf($contract, 'reference', 'No executed contract'),
                $contract?->effective_date?->toDateString() ?? '',
                $contract?->expiry_date?->toDateString() ?? ($contract ? 'Open-ended' : ''),
                $this->clauseVerdict($engagement, self::AGREEMENT_CLAUSE),
                $this->labelOf($contract, 'counterparty_signatory', 'Not recorded'),
                $this->labelOf($contract?->internalSignatory, 'name', 'Not recorded'),
            ];
        })->all();

        $missing = $tpsps->filter(
            fn (Engagement $engagement) => ! $this->agreementIsAcknowledged($engagement)
        )->count();

        return [
            'code' => '12.8.2',
            'title' => 'Written agreements acknowledging responsibility for cardholder data',
            'citation' => 'PCI DSS v4.0.1 requirement 12.8.2',
            'coverage' => $missing === 0
                ? DoraRegisterBuilder::COVERAGE_COMPLETE
                : DoraRegisterBuilder::COVERAGE_PARTIAL,
            'note' => $missing === 0
                ? null
                : $missing.' of '.$tpsps->count().' providers have no confirmed acknowledgement clause. '
                    .'A clause that was never analysed reads "Not analysed", not "absent" — the absence of the '
                    .'check is not evidence about the term.',
            'headers' => [
                'Service provider', 'Engagement', 'Contract', 'Effective', 'Expires',
                'Acknowledgement clause', 'Counterparty signatory', 'Internal signatory',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, mixed>
     */
    private function dueDiligenceEvidence(Collection $tpsps): array
    {
        $checklists = DueDiligenceChecklist::query()
            ->with('items')
            ->whereIn('engagement_id', $tpsps->modelKeys())
            ->get()
            ->keyBy('engagement_id');

        $withoutChecklist = $tpsps->reject(
            fn (Engagement $engagement) => $checklists->has($engagement->getKey())
        )->count();

        return [
            'code' => '12.8.3',
            'title' => 'Due diligence performed before engaging the provider',
            'citation' => 'PCI DSS v4.0.1 requirement 12.8.3',
            'coverage' => $withoutChecklist === 0
                ? DoraRegisterBuilder::COVERAGE_COMPLETE
                : DoraRegisterBuilder::COVERAGE_PARTIAL,
            'note' => $withoutChecklist === 0
                ? null
                : $withoutChecklist.' providers have no due diligence checklist on record.',
            'headers' => [
                'Service provider', 'Engagement', 'Checklist', 'Status', 'Items complete',
                'Items outstanding', 'Mandatory items waived', 'Completed on', 'Completed by',
            ],
            'rows' => $tpsps->map(function (Engagement $engagement) use ($checklists) {
                $checklist = $checklists->get($engagement->getKey());

                if ($checklist === null) {
                    return [
                        $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                        $engagement->reference,
                        'None on record', 'No due diligence recorded', 0, 'Unknown', 0, '', '',
                    ];
                }

                $items = $checklist->items;

                return [
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->reference,
                    $checklist->template_code ?: 'Checklist #'.$checklist->getKey(),
                    ucwords(str_replace('_', ' ', (string) $checklist->status)),
                    $items->where('status', 'complete')->count(),
                    $items->reject(fn ($item) => $item->isSettled())->count(),
                    // A waived mandatory item is due diligence that did not
                    // happen, recorded honestly. A QSA will ask about each one.
                    $items->where('is_mandatory', true)->where('status', 'waived')->count(),
                    $checklist->completed_at?->toDateString() ?? 'Not completed',
                    $this->labelOf($checklist->completer, 'name', 'Not recorded'),
                ];
            })->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, mixed>
     */
    private function monitoringLog(Collection $tpsps): array
    {
        $attestations = $this->attestations($tpsps);

        $failing = $tpsps->filter(function (Engagement $engagement) use ($attestations) {
            $reading = $attestations[$engagement->getKey()] ?? null;

            return $reading === null || $reading['months'] === null || $reading['months'] > self::CURRENCY_MONTHS;
        });

        return [
            'code' => '12.8.4',
            'title' => 'Compliance-status monitoring log',
            'citation' => 'PCI DSS v4.0.1 requirement 12.8.4',
            'coverage' => $failing->isEmpty()
                ? DoraRegisterBuilder::COVERAGE_COMPLETE
                : DoraRegisterBuilder::COVERAGE_PARTIAL,
            'note' => $failing->isEmpty()
                ? 'Every provider has an Attestation of Compliance issued within the last twelve months.'
                : $failing->count().' of '.$tpsps->count().' providers fail the twelve-month currency test: '
                    .'no attestation on file, or one older than twelve months. An undated attestation fails it '
                    .'too — a document whose age cannot be established cannot evidence annual monitoring.',
            'headers' => [
                'Service provider', 'Engagement', 'Attestation on file', 'Issued', 'Valid to',
                'Age (months)', 'Twelve-month test', 'Last assessment validated', 'Next assessment due',
            ],
            'rows' => $tpsps->map(function (Engagement $engagement) use ($attestations) {
                $reading = $attestations[$engagement->getKey()] ?? null;

                return [
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->reference,
                    $reading === null ? 'None held' : $reading['title'],
                    $reading['issued'] ?? '',
                    $reading['valid_to'] ?? '',
                    $reading['months'] ?? 'Unknown',
                    $this->currencyVerdict($reading),
                    $this->lastValidatedAssessment($engagement),
                    $engagement->next_assessment_due?->toDateString() ?? 'Not scheduled',
                ];
            })->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<string, mixed>
     */
    private function responsibilityMatrices(Collection $tpsps): array
    {
        $unconfirmed = $tpsps->filter(
            fn (Engagement $engagement) => $this->matrixCounts($engagement)['unconfirmed'] > 0
        );

        return [
            'code' => '12.8.5',
            'title' => 'Responsibility matrices',
            'citation' => 'PCI DSS v4.0.1 requirement 12.8.5',
            'coverage' => $unconfirmed->isEmpty()
                ? DoraRegisterBuilder::COVERAGE_COMPLETE
                : DoraRegisterBuilder::COVERAGE_PARTIAL,
            'note' => $unconfirmed->isEmpty()
                ? null
                : $unconfirmed->count().' matrices carry rows nobody has confirmed. An unconfirmed row is the '
                    .'vendor\'s own view of who owns a requirement, not an agreed position, and it is marked as '
                    .'such rather than counted as agreed.',
            'headers' => [
                'Service provider', 'Engagement', 'Requirements covered', 'Confirmed',
                'Unconfirmed', 'Provider-owned', 'Entity-owned', 'Shared', 'Not applicable',
                'Matrix clause in contract',
            ],
            'rows' => $tpsps->map(function (Engagement $engagement) {
                $counts = $this->matrixCounts($engagement);

                return [
                    $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    $engagement->reference,
                    $counts['total'],
                    $counts['confirmed'],
                    $counts['unconfirmed'],
                    $counts[PciResponsibility::TPSP],
                    $counts[PciResponsibility::ENTITY],
                    $counts[PciResponsibility::SHARED],
                    $counts[PciResponsibility::NOT_APPLICABLE],
                    $this->clauseVerdict($engagement, self::MATRIX_CLAUSE),
                ];
            })->all(),
        ];
    }

    /* ================================================================== */

    /**
     * @return Collection<int, Engagement>
     */
    private function tpsps(): Collection
    {
        return Engagement::query()
            ->with([
                'thirdParty:id,legal_name,registration_number',
                'businessUnit:id,name',
                'relationshipOwner:id,name',
                'contracts' => fn ($query) => $query->with('internalSignatory:id,name'),
            ])
            ->where('pci_in_scope', true)
            ->orderBy('reference')
            ->get();
    }

    /** @var array<int, array<string, string>> */
    private array $clauseCache = [];

    /**
     * The clause resolver's own verdict, not a second vocabulary for it.
     *
     * `ClauseResolution` distinguishes an analysed-and-absent clause from an
     * unanalysed one in its LABEL and not in its value: `presence` defaults to
     * `absent` where no determination exists, while `presence_label` reads
     * "Not analysed". Reading the value here would report every contract
     * nobody has analysed as missing its PCI acknowledgement — which is the
     * conclusion a QSA would act on, drawn from evidence that does not exist.
     */
    private function clauseVerdict(Engagement $engagement, string $code): string
    {
        if (! isset($this->clauseCache[$engagement->getKey()])) {
            $resolution = $this->clauses->resolve($engagement, $this->executedContract($engagement));

            $labels = [];
            foreach ($resolution->toArray()['clauses'] as $clause) {
                $labels[$clause['code']] = (string) $clause['presence_label'];
            }

            $this->clauseCache[$engagement->getKey()] = $labels;
        }

        // Not applicable to this engagement, or removed from the library. Both
        // are stated: the absence of the check is not evidence about the term.
        return $this->clauseCache[$engagement->getKey()][$code] ?? 'Not applicable or not in the clause library';
    }

    private function agreementIsAcknowledged(Engagement $engagement): bool
    {
        return $this->clauseVerdict($engagement, self::AGREEMENT_CLAUSE) === 'Present';
    }

    private function executedContract(Engagement $engagement): ?Contract
    {
        return $engagement->contracts->firstWhere('status', 'executed')
            ?? $engagement->contracts->first();
    }

    /**
     * The latest Attestation of Compliance per engagement.
     *
     * Age is measured from the ISSUE date, not from `valid_to`. An AoC states
     * a position at a point in time; a validity window written on top of it by
     * whoever filed it is not what 12.8.4 counts.
     *
     * @param  Collection<int, Engagement>  $tpsps
     * @return array<int, array{title: string, issued: string, valid_to: string, months: ?int}>
     */
    private function attestations(Collection $tpsps): array
    {
        if ($tpsps->isEmpty()) {
            return [];
        }

        $providerIds = $tpsps->pluck('third_party_id')->filter()->unique()->values()->all();

        $documents = Document::query()
            ->select('tp_documents.owner_type', 'tp_documents.owner_id', 'tp_documents.title',
                'tp_documents.issue_date', 'tp_documents.valid_to')
            ->join('tp_document_types', 'tp_document_types.id', '=', 'tp_documents.document_type_id')
            ->where('tp_document_types.code', 'pci_aoc')
            ->where('tp_documents.is_superseded', false)
            ->where(function ($query) use ($tpsps, $providerIds) {
                $query->where(fn ($q) => $q
                    ->where('tp_documents.owner_type', Document::OWNER_ENGAGEMENT)
                    ->whereIn('tp_documents.owner_id', $tpsps->modelKeys()));

                if ($providerIds !== []) {
                    $query->orWhere(fn ($q) => $q
                        ->where('tp_documents.owner_type', Document::OWNER_THIRD_PARTY)
                        ->whereIn('tp_documents.owner_id', $providerIds));
                }
            })
            ->orderByDesc('tp_documents.issue_date')
            ->get();

        $byOwner = $documents->groupBy(fn (Document $document) => $document->owner_type.':'.$document->owner_id);

        $result = [];

        foreach ($tpsps as $engagement) {
            $held = collect()
                ->merge($byOwner->get(Document::OWNER_ENGAGEMENT.':'.$engagement->getKey(), collect()))
                ->merge($byOwner->get(Document::OWNER_THIRD_PARTY.':'.$engagement->third_party_id, collect()));

            $latest = $held->first();

            if ($latest === null) {
                continue;
            }

            $result[$engagement->getKey()] = [
                'title' => (string) $latest->title,
                'issued' => $latest->issue_date?->toDateString() ?? 'Not dated',
                'valid_to' => $latest->valid_to?->toDateString() ?? 'No expiry recorded',
                'months' => $latest->issue_date === null
                    ? null
                    : (int) $latest->issue_date->diffInMonths(now()),
            ];
        }

        return $result;
    }

    private function currencyVerdict(?array $reading): string
    {
        if ($reading === null) {
            return 'Fails — no attestation on file';
        }

        if ($reading['months'] === null) {
            return 'Fails — attestation is undated';
        }

        return $reading['months'] > self::CURRENCY_MONTHS
            ? 'Fails — '.$reading['months'].' months old'
            : 'Passes';
    }

    /**
     * @return array<string, int>
     */
    private function matrixCounts(Engagement $engagement): array
    {
        $rows = PciResponsibility::query()
            ->where('engagement_id', $engagement->getKey())
            ->get();

        $counts = [
            'total' => $rows->count(),
            'confirmed' => $rows->filter(fn (PciResponsibility $row) => $row->isConfirmed())->count(),
        ];

        $counts['unconfirmed'] = $counts['total'] - $counts['confirmed'];

        foreach (PciResponsibility::RESPONSIBILITIES as $responsibility) {
            $counts[$responsibility] = $rows->where('responsibility', $responsibility)->count();
        }

        return $counts;
    }

    private function lastValidatedAssessment(Engagement $engagement): string
    {
        $validated = $engagement->assessments()->whereNotNull('validated_at')->max('validated_at');

        return $validated ? substr((string) $validated, 0, 10) : 'No validated assessment';
    }
}
