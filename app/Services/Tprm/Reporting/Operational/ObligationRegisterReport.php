<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Enums\Tprm\ObligationStatus;
use App\Models\Tprm\Obligation;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * Every duty either side owes, and whether it was discharged — FR-RPT-07.
 *
 * IT REPORTS BOTH OBLIGORS. Half the register is what the vendor owes us and
 * half is what we owe the vendor, and a report that showed only the first
 * would be the institution auditing its supplier while missing its own
 * breaches — which is the half a supervisor asks about.
 *
 * AN OBLIGATION REQUIRING EVIDENCE AND HOLDING NONE IS FLAGGED EVEN WHERE ITS
 * STATUS SAYS SATISFIED. "Marked satisfied with nothing attached" is a
 * different claim from "satisfied", and only one of them survives an audit.
 */
class ObligationRegisterReport implements OperationalReport
{
    use StatesAbsence;

    public function key(): string
    {
        return 'obligation-register';
    }

    public function title(): string
    {
        return 'Obligation register';
    }

    public function description(): string
    {
        return 'Contractual duties on both sides, their next due dates, and which were marked satisfied '
            .'without the evidence they require.';
    }

    public function permission(): string
    {
        return 'tprm.contract.view';
    }

    public function headers(): array
    {
        return [
            'Engagement', 'Provider', 'Contract', 'Obligation', 'Owed by', 'Owner', 'Frequency',
            'Due', 'Next due', 'Status', 'Breaches', 'Evidence required', 'Evidence held', 'Citation',
        ];
    }

    public function rows(): array
    {
        $obligations = Obligation::query()
            ->with([
                'engagement:id,reference,third_party_id',
                'engagement.thirdParty:id,legal_name',
                'contract:id,reference',
                'owner:id,name',
            ])
            ->get();

        return $obligations
            ->sortBy([
                // Breached first, then by what is due soonest.
                fn (Obligation $a, Obligation $b) => $this->severity($b) <=> $this->severity($a),
                fn (Obligation $a, Obligation $b) => ($a->next_due_date?->toDateString() ?? '9999-12-31')
                    <=> ($b->next_due_date?->toDateString() ?? '9999-12-31'),
            ])
            ->map(fn (Obligation $obligation) => [
                $this->labelOf($obligation->engagement, 'reference', 'Not linked'),
                $this->labelOf($obligation->engagement?->thirdParty, 'legal_name', 'Not recorded'),
                $this->labelOf($obligation->contract, 'reference', 'Not linked'),
                $obligation->title,
                $obligation->obligor ? ucfirst($obligation->obligor) : 'Not recorded',
                $this->labelOf($obligation->owner, 'name', 'Unassigned'),
                $obligation->frequency ? ucwords(str_replace('_', ' ', $obligation->frequency)) : 'One-off',
                $obligation->due_date?->toDateString() ?? '',
                $obligation->next_due_date?->toDateString() ?? 'None scheduled',
                $obligation->status->label(),
                $obligation->breach_count,
                $obligation->evidence_required ? 'Yes' : 'No',
                $this->evidenceLabel($obligation),
                $obligation->citation ?: '',
            ])
            ->values()
            ->all();
    }

    public function notes(): array
    {
        return [
            'Population' => 'Every obligation on the register, both obligors',
            'Ordering' => 'Breached first, then by next due date',
        ];
    }

    private function severity(Obligation $obligation): int
    {
        return match ($obligation->status) {
            ObligationStatus::Breached => 3,
            ObligationStatus::Due => 2,
            ObligationStatus::Pending => 1,
            default => 0,
        };
    }

    private function evidenceLabel(Obligation $obligation): string
    {
        if (! $obligation->evidence_required) {
            return 'Not required';
        }

        if ($obligation->evidence_document_id !== null) {
            return 'Held';
        }

        // A different claim from "satisfied", and only one survives an audit.
        return $obligation->status === ObligationStatus::Satisfied
            ? 'MARKED SATISFIED WITH NO EVIDENCE ATTACHED'
            : 'Not held';
    }
}
