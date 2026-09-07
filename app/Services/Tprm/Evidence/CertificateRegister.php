<?php

namespace App\Services\Tprm\Evidence;

use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;

/**
 * The certificate register — FR-DDL-07.
 *
 * "Each with issuer, scope, issue and expiry, and a scope-mismatch check that
 * compares the certificate scope text against the engagement's service
 * description and flags a mismatch."
 *
 * WHAT MAKES THIS A REGISTER RATHER THAN A LIST OF FILES is that it enumerates
 * the certificates we EXPECT and reports each as held, expired or absent. A
 * screen that lists what was uploaded can only ever answer "what do we have";
 * the question a reviewer has is "what is missing", and an absence does not
 * show up in a list of presences.
 *
 * ISO/IEC 20000 IS FLAGGED AS EXPECTED FOR ICT THIRD PARTIES, per the CBN IT
 * Standards Blueprint §2.5.1. It is the one entry here whose expectation comes
 * from a Nigerian supervisory document rather than from general practice, and
 * it is the one a bank examined against that Blueprint would be asked about.
 * Expected, not required: the module flags an absence for a human to explain,
 * and blocks nothing.
 *
 * A CERTIFICATE ROW SPANS EVERY ENGAGEMENT WITH THE VENDOR, but the scope
 * check does not — a certificate can cover one service we buy and not another,
 * which is exactly the failure FR-DDL-07 exists to catch. So the check runs per
 * engagement and the register reports the engagements a certificate does not
 * appear to cover.
 */
class CertificateRegister
{
    public function __construct(private readonly ScopeMatcher $scopes) {}

    /**
     * The certificate types the register enumerates, in the order FR-DDL-07
     * lists them.
     *
     * `expected_for` is null where a certificate is informative rather than
     * anticipated — an ISO 9001 is a quality-management certificate and its
     * absence says nothing about a vendor's security.
     *
     * @var list<array{code: string, label: string, expected_for: string|null, note: string|null}>
     */
    private const EXPECTED = [
        ['code' => 'iso27001_cert', 'label' => 'ISO/IEC 27001', 'expected_for' => 'any', 'note' => 'Read the scope statement, not the certificate number: the scope decides whether it covers the service we buy.'],
        ['code' => 'iso22301_cert', 'label' => 'ISO 22301', 'expected_for' => 'critical', 'note' => 'Business continuity. Expected where the vendor supports a critical or important function.'],
        ['code' => 'iso20000_cert', 'label' => 'ISO/IEC 20000', 'expected_for' => 'ict', 'note' => 'IT service management. The CBN IT Standards Blueprint §2.5.1 anticipates this for ICT third parties.'],
        ['code' => 'iso9001_cert', 'label' => 'ISO 9001', 'expected_for' => null, 'note' => 'Quality management. Informative; its absence says nothing about security.'],
        ['code' => 'pci_aoc', 'label' => 'PCI DSS AOC', 'expected_for' => 'pci', 'note' => 'Expected where the engagement is in PCI scope. Check the services EXCLUDED as carefully as those assessed.'],
        ['code' => 'soc2_type2', 'label' => 'SOC 2 Type II', 'expected_for' => 'any', 'note' => null],
        ['code' => 'soc1', 'label' => 'SOC 1', 'expected_for' => null, 'note' => 'Controls over financial reporting. Expected where the service feeds our books.'],
        ['code' => 'ndpc_registration', 'label' => 'NDPC registration', 'expected_for' => 'personal_data', 'note' => 'Expected where the vendor processes personal data as our processor.'],
        ['code' => 'regulator_licence', 'label' => 'Regulatory licence', 'expected_for' => null, 'note' => 'Expected where the vendor performs a licensed activity.'],
        ['code' => 'insurance_cert', 'label' => 'Insurance', 'expected_for' => 'any', 'note' => 'Check the named insured against the entity we contract with.'],
    ];

    /**
     * @return array{certificates: list<array<string, mixed>>, missing_expected: int, expired: int}
     */
    public function for(ThirdParty $thirdParty): array
    {
        $engagements = Engagement::query()
            ->where('third_party_id', $thirdParty->getKey())
            ->get(['id', 'reference', 'name', 'service_description', 'service_type_id', 'effective_tier', 'pci_in_scope', 'processes_personal_data', 'supports_critical_function', 'engagement_type']);

        $documents = $this->documentsFor($thirdParty, $engagements->pluck('id')->all());
        $typeIds = $this->typeIdsByCode();

        $certificates = [];
        $missing = 0;
        $expired = 0;

        foreach (self::EXPECTED as $entry) {
            $typeId = $typeIds[$entry['code']] ?? null;

            $held = $documents
                ->filter(fn (Document $document) => $document->document_type_id === $typeId)
                // Newest expiry first, so the row reports the certificate that
                // is actually current rather than whichever was uploaded last.
                ->sortByDesc(fn (Document $document) => $document->valid_to->timestamp ?? PHP_INT_MAX)
                ->values();

            $current = $held->first(fn (Document $document) => $document->isCurrent());
            $expectedHere = $this->isExpected($entry['expected_for'], $engagements);

            if ($current === null && $expectedHere) {
                $missing++;
            }

            if ($current === null && $held->isNotEmpty()) {
                $expired++;
            }

            $certificates[] = [
                'code' => $entry['code'],
                'label' => $entry['label'],
                'note' => $entry['note'],
                'expected' => $expectedHere,
                'expectation_basis' => $expectedHere ? $this->basis($entry['expected_for']) : null,
                'status' => match (true) {
                    $current !== null => 'held',
                    $held->isNotEmpty() => 'expired',
                    $expectedHere => 'missing',
                    default => 'not_held',
                },
                'document' => $current === null ? null : $this->documentPayload($current),
                'superseded_or_expired' => $held
                    ->reject(fn (Document $document) => $current !== null && $document->is($current))
                    ->map(fn (Document $document) => $this->documentPayload($document))
                    ->values()->all(),
                // FR-DDL-07: the engagements this certificate does not appear
                // to cover, each with the modifier a confirmed mismatch would
                // apply.
                'scope_gaps' => $current === null ? [] : $this->scopeGaps($current, $engagements),
            ];
        }

        return [
            'certificates' => $certificates,
            'missing_expected' => $missing,
            'expired' => $expired,
        ];
    }

    /**
     * Documents attached to the vendor itself or to any of its engagements.
     *
     * Both, because banks file certificates in both places and a register that
     * looked in only one would report a certificate missing while it sits on
     * the engagement two clicks away.
     *
     * @param  list<int>  $engagementIds
     * @return \Illuminate\Support\Collection<int, Document>
     */
    private function documentsFor(ThirdParty $thirdParty, array $engagementIds)
    {
        return Document::query()
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_THIRD_PARTY)
                    ->where('owner_id', $thirdParty->getKey()))
                ->orWhere(fn ($inner) => $inner
                    ->where('owner_type', Document::OWNER_ENGAGEMENT)
                    ->whereIn('owner_id', $engagementIds ?: [0])))
            ->whereNotNull('document_type_id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Engagement>  $engagements
     * @return list<array<string, mixed>>
     */
    private function scopeGaps(Document $document, $engagements): array
    {
        return $engagements
            ->map(fn (Engagement $engagement) => [
                'engagement' => $engagement->reference,
                'name' => $engagement->name,
                'check' => $this->scopes->check($document, $engagement),
            ])
            ->filter(fn (array $row) => $row['check']['mismatch'])
            ->map(fn (array $row) => [
                'engagement' => $row['engagement'],
                'name' => $row['name'],
                'overlap' => $row['check']['overlap'],
                'modifier' => $row['check']['modifier'],
            ])
            ->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Engagement>  $engagements
     */
    private function isExpected(?string $expectedFor, $engagements): bool
    {
        if ($expectedFor === null || $engagements->isEmpty()) {
            return false;
        }

        return match ($expectedFor) {
            'any' => true,
            'critical' => $engagements->contains(fn (Engagement $e) => (bool) $e->supports_critical_function),
            'pci' => $engagements->contains(fn (Engagement $e) => (bool) $e->pci_in_scope),
            'personal_data' => $engagements->contains(fn (Engagement $e) => (bool) $e->processes_personal_data),
            'ict' => $engagements->contains(fn (Engagement $e) => $e->engagement_type?->value === 'ict_service'),
            default => false,
        };
    }

    private function basis(?string $expectedFor): ?string
    {
        return match ($expectedFor) {
            'any' => 'Expected of any third party holding this kind of relationship.',
            'critical' => 'An engagement with this vendor supports a critical or important business function.',
            'pci' => 'An engagement with this vendor is in PCI DSS scope.',
            'personal_data' => 'An engagement with this vendor processes personal data.',
            'ict' => 'An engagement with this vendor is an ICT service — CBN IT Standards Blueprint §2.5.1.',
            default => null,
        };
    }

    /** @return array<string, int> */
    private function typeIdsByCode(): array
    {
        return DocumentType::query()
            ->availableTo()
            ->whereIn('code', array_column(self::EXPECTED, 'code'))
            ->pluck('id', 'code')
            ->all();
    }

    /** @return array<string, mixed> */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'uuid' => $document->uuid,
            'title' => $document->title,
            'issuer' => $document->issuer,
            'scope_text' => $document->scope_text,
            'issue_date' => $document->issue_date?->toDateString(),
            'valid_to' => $document->valid_to?->toDateString(),
            'days_until_expiry' => $document->daysUntilExpiry(),
            'is_expired' => $document->isExpired(),
            'url' => route('tprm.documents.show', $document),
        ];
    }
}
