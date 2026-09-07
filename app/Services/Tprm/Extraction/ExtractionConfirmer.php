<?php

namespace App\Services\Tprm\Extraction;

use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Models\Tprm\Soc2Cuec;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\Soc2Exception;
use App\Models\Tprm\Soc2SubserviceOrg;
use App\Services\Tprm\Graph\NthPartyService;
use Illuminate\Support\Facades\DB;

/**
 * The FIRST confirmation — "yes, the extractor read this document correctly".
 *
 * It turns a `pending` extraction into structured rows: a SOC 2 into
 * `tp_soc2_details` and its three children, a certificate into the document's
 * own issuer, scope and validity fields. It does NOT touch the register. The
 * second confirmation, which does, is `Soc2Cascade` plus its applier, and the
 * two are separate because they are different judgements made with different
 * information.
 *
 * A HUMAN MAY CORRECT ANYTHING BEFORE CONFIRMING, and what they changed is
 * recorded in `corrections`. That column is the only honest measurement of
 * extractor accuracy there is — the alternative is the model's own confidence
 * score marking its own homework — and it is why the corrections are diffed
 * here rather than the confirmed values simply overwriting the extracted ones.
 *
 * THE STRUCTURED ROWS ARE WRITTEN THE SAME WAY WHETHER A MODEL OR A PERSON
 * PRODUCED THE FIELDS. `manual()` builds an extraction row from typed input
 * and confirms it in one step, so the manual path lands in exactly the same
 * tables through exactly the same code. That is what makes AC-16 fall out
 * rather than needing a parallel implementation nobody tests.
 */
class ExtractionConfirmer
{
    /**
     * Confirm an extraction, optionally with corrections.
     *
     * @param  array<string, mixed>  $corrections  field => corrected value
     */
    public function confirm(DocumentExtraction $extraction, ?int $userId = null, array $corrections = []): DocumentExtraction
    {
        return DB::transaction(function () use ($extraction, $userId, $corrections) {
            $extracted = (array) $extraction->extracted;
            $confirmed = $corrections === [] ? $extracted : array_replace($extracted, $corrections);

            $extraction->forceFill([
                'extracted' => $confirmed,
                'corrections' => $this->diff($extracted, $confirmed),
                'status' => DocumentExtraction::STATUS_CONFIRMED,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ])->save();

            $this->materialise($extraction->document, $extraction, $confirmed, $userId);

            return $extraction->refresh();
        });
    }

    public function reject(DocumentExtraction $extraction, ?int $userId = null): DocumentExtraction
    {
        $extraction->forceFill([
            'status' => DocumentExtraction::STATUS_REJECTED,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ])->save();

        return $extraction->refresh();
    }

    /**
     * Record fields a person typed by hand, already confirmed.
     *
     * The AC-16 path. It writes an extraction row with no model and no prompt
     * version — those columns being null is exactly what says "a person did
     * this" — and then goes through the same `materialise()` as everything
     * else.
     *
     * @param  array<string, mixed>  $fields
     */
    public function manual(Document $document, string $extractor, array $fields, ?int $userId = null): DocumentExtraction
    {
        $extraction = DocumentExtraction::create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->getKey(),
            'extractor' => $extractor,
            'model' => null,
            'prompt_version' => null,
            'extracted' => $fields,
            // Not 1.0. Confidence is a property of an EXTRACTION, and a person
            // typing a field is not making a probabilistic claim about it —
            // null says the question does not apply, where 1.0 would put
            // manual entry above every model output in any comparison.
            'confidence' => null,
            'citations' => null,
            'status' => DocumentExtraction::STATUS_CONFIRMED,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        $this->materialise($document, $extraction, $fields, $userId);

        return $extraction->refresh();
    }

    /**
     * Write the structured rows for a confirmed extraction.
     *
     * @param  array<string, mixed>  $fields
     */
    private function materialise(Document $document, DocumentExtraction $extraction, array $fields, ?int $userId = null): void
    {
        match ($extraction->extractor?->value) {
            'soc2' => $this->materialiseSoc2($document, $fields, $userId),
            'iso_cert', 'pci_aoc' => $this->materialiseCertificate($document, $fields),
            default => $this->materialiseDates($document, $fields),
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function materialiseSoc2(Document $document, array $fields, ?int $userId = null): void
    {
        $soc2 = Soc2Detail::updateOrCreate(
            ['document_id' => $document->getKey()],
            [
                'organization_id' => $document->organization_id,
                'report_type' => $fields['report_type'] ?? Soc2Detail::TYPE_II,
                'period_start' => $fields['period_start'] ?? null,
                'period_end' => $fields['period_end'] ?? null,
                'service_auditor' => $fields['service_auditor'] ?? null,
                'scope_description' => $fields['scope_description'] ?? null,
                'tsc_categories' => $fields['tsc_categories'] ?? [],
                'opinion_type' => $fields['opinion_type'] ?? null,
                'qualification_basis' => $fields['qualification_basis'] ?? null,
                'exception_count' => count((array) ($fields['exceptions'] ?? [])),
                'subservice_method' => $fields['subservice_method'] ?? null,
            ]
        );

        // Replaced rather than merged. A re-confirmation after corrections is
        // the human's final word on what the report says; merging would leave
        // an exception they deleted still sitting in the register, and a
        // finding would be raised from it.
        $soc2->exceptions()->delete();
        $soc2->cuecs()->delete();
        $soc2->subserviceOrgs()->delete();

        foreach ((array) ($fields['exceptions'] ?? []) as $row) {
            Soc2Exception::create([
                'organization_id' => $document->organization_id,
                'soc2_id' => $soc2->getKey(),
                'control_reference' => $row['control_reference'] ?? null,
                'description' => $row['description'] ?? '',
                'population' => $row['population'] ?? null,
                'exceptions_noted' => $row['exceptions_noted'] ?? null,
                'management_response' => $row['management_response'] ?? null,
                'severity_assessment' => $row['severity_assessment'] ?? null,
            ]);
        }

        foreach ((array) ($fields['cuecs'] ?? []) as $row) {
            Soc2Cuec::create([
                'organization_id' => $document->organization_id,
                'soc2_id' => $soc2->getKey(),
                'cuec_reference' => $row['cuec_reference'] ?? null,
                'description' => $row['description'] ?? '',
            ]);
        }

        foreach ((array) ($fields['subservice_orgs'] ?? []) as $row) {
            Soc2SubserviceOrg::create([
                'organization_id' => $document->organization_id,
                'soc2_id' => $soc2->getKey(),
                'name' => $row['name'] ?? '',
                'services' => $row['services'] ?? null,
                'method' => $row['method'] ?? Soc2SubserviceOrg::METHOD_CARVE_OUT,
            ]);
        }

        /*
         * Each CARVE-OUT becomes a proposed nth-party edge (Phase 7, build
         * item 2). This is the moment to do it: a carve-out is the auditor
         * saying "I examined nothing this organisation does", which is a
         * fourth-party exposure the bank now knows about and did not a minute
         * ago. Waiting for somebody to open a graph screen and press a button
         * would mean the exposure is recorded in a subservice table nothing
         * reads.
         *
         * The proposals are PROPOSALS. Confirming a SOC 2 extraction does not
         * silently extend the supply-chain map.
         */
        app(NthPartyService::class)->fromSoc2Carveouts($soc2->refresh(), $userId);

        // The report's own period is the document's validity. Evidence expires
        // when the period it opines on ends — not when a certificate says so,
        // because a SOC 2 carries no expiry date at all.
        $document->forceFill(array_filter([
            'issuer' => $fields['service_auditor'] ?? null,
            'scope_text' => $fields['scope_description'] ?? null,
            'valid_from' => $fields['period_start'] ?? null,
            'valid_to' => $fields['period_end'] ?? null,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function materialiseCertificate(Document $document, array $fields): void
    {
        $document->forceFill(array_filter([
            'issuer' => $fields['certification_body'] ?? $fields['qsa_company'] ?? null,
            // The field FR-DDL-07's mismatch check reads. For a PCI AOC the
            // assessed services ARE the scope, joined rather than dropped.
            'scope_text' => $fields['scope_text']
                ?? ($fields['services_assessed'] ?? null ? implode('; ', (array) $fields['services_assessed']) : null),
            'issue_date' => $fields['issue_date'] ?? $fields['assessment_date'] ?? null,
            'valid_from' => $fields['valid_from'] ?? $fields['assessment_date'] ?? null,
            'valid_to' => $fields['valid_to'] ?? $fields['expiry_date'] ?? null,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * Everything else: whatever dates the extraction found, so the expiry
     * monitor can see the document at all.
     *
     * @param  array<string, mixed>  $fields
     */
    private function materialiseDates(Document $document, array $fields): void
    {
        $document->forceFill(array_filter([
            'valid_from' => $fields['period_from'] ?? $fields['test_date'] ?? $fields['report_date'] ?? null,
            'valid_to' => $fields['period_to'] ?? $fields['next_test_due'] ?? null,
            'issuer' => $fields['insurer'] ?? $fields['testing_firm'] ?? $fields['auditor'] ?? null,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * What the human changed.
     *
     * Only the fields whose value actually differs, and recorded as before/
     * after rather than as the new value alone — "the model said Deloitte and
     * a person changed it to Grant Thornton" is the training signal; "a person
     * confirmed Grant Thornton" is not.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>|null
     */
    private function diff(array $before, array $after): ?array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if ($key === '_meta') {
                continue;
            }

            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes === [] ? null : $changes;
    }
}
