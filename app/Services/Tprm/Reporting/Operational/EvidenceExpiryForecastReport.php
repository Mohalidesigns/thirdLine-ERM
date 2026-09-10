<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Models\Tprm\Document;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * What assurance lapses next, and what has lapsed already — FR-RPT-07.
 *
 * ALREADY-EXPIRED EVIDENCE LEADS THE REPORT. A forecast that only looked
 * forward would answer "what should I chase next quarter" while leaving out
 * the controls that are unevidenced today — and TRD §7.4 has already decayed
 * the assurance coefficient for those, so the score moved before anyone was
 * told.
 *
 * THE HORIZON IS A PARAMETER OF THE REPORT AND IS PRINTED WITH IT. A forecast
 * with an unstated window is a list somebody will read as complete.
 */
class EvidenceExpiryForecastReport implements OperationalReport
{
    use StatesAbsence;

    /** Matches the longest notice window in ExpiryMonitor::WINDOWS. */
    private const HORIZON_DAYS = 90;

    public function key(): string
    {
        return 'evidence-expiry-forecast';
    }

    public function title(): string
    {
        return 'Evidence expiry forecast';
    }

    public function description(): string
    {
        return 'Assurance evidence that has already expired, and what lapses within the next '
            .self::HORIZON_DAYS.' days.';
    }

    public function permission(): string
    {
        return 'tprm.evidence.view';
    }

    public function headers(): array
    {
        return [
            'State', 'Document', 'Type', 'Assurance evidence', 'Owner kind', 'Issuer', 'Issued',
            'Valid from', 'Valid to', 'Days', 'Superseded',
        ];
    }

    public function rows(): array
    {
        $horizon = now()->addDays(self::HORIZON_DAYS)->toDateString();

        $documents = Document::query()
            ->with('documentType:id,name,is_assurance_evidence')
            ->where('is_superseded', false)
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<=', $horizon)
            ->orderBy('valid_to')
            ->get();

        return $documents->map(function (Document $document) {
            $days = (int) now()->startOfDay()->diffInDays($document->valid_to, false);

            return [
                // Already expired is not "expiring soon". The control is
                // unevidenced now, and the assurance coefficient has moved.
                $days < 0 ? 'EXPIRED' : 'Expiring',
                $document->title,
                $this->labelOf($document->documentType, 'name', 'Not classified'),
                $document->documentType?->is_assurance_evidence ? 'Yes' : 'No',
                $document->ownerLabel(),
                $document->issuer ?: 'Not recorded',
                $document->issue_date?->toDateString() ?? 'Not dated',
                $document->valid_from?->toDateString() ?? '',
                $document->valid_to->toDateString(),
                $days,
                $document->is_superseded ? 'Yes' : 'No',
            ];
        })->values()->all();
    }

    public function notes(): array
    {
        return [
            'Horizon' => self::HORIZON_DAYS.' days',
            'Population' => 'Documents carrying an expiry date and not superseded',
            'Already expired' => 'Included and listed first — the control is unevidenced today',
        ];
    }
}
