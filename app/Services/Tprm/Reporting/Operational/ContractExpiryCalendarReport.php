<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Models\Tprm\Contract;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * Contract expiry and the notice calendar — FR-RPT-07.
 *
 * THE NOTICE DEADLINE IS THE DATE THAT MATTERS, NOT THE EXPIRY. An
 * auto-renewing contract with a ninety-day notice period is decided three
 * months before it expires; a report sorted on expiry date shows it as
 * comfortable right up to the week the institution loses the right to leave.
 * Rows are therefore ordered by whichever of the two comes first.
 *
 * A MISSED NOTICE WINDOW IS STATED AS SUCH. `Contract::noticeWindowMissed()`
 * already answers it, and printing "expires in 40 days" over a contract that
 * silently renewed last month would be the most expensive kind of true
 * statement.
 */
class ContractExpiryCalendarReport implements OperationalReport
{
    use StatesAbsence;

    public function key(): string
    {
        return 'contract-expiry-calendar';
    }

    public function title(): string
    {
        return 'Contract expiry and notice calendar';
    }

    public function description(): string
    {
        return 'Contracts by the date the institution must act, which for an auto-renewing agreement is the '
            .'notice deadline rather than the expiry.';
    }

    public function permission(): string
    {
        return 'tprm.contract.view';
    }

    public function headers(): array
    {
        return [
            'Contract', 'Title', 'Engagement', 'Provider', 'Type', 'Status', 'Effective', 'Expires',
            'Renewal', 'Notice period (days)', 'Notice deadline', 'Days to act', 'Notice window',
            'Blocking clause gaps',
        ];
    }

    public function rows(): array
    {
        $contracts = Contract::query()
            ->with(['engagement.thirdParty:id,legal_name'])
            ->get();

        return $contracts
            ->sortBy(fn (Contract $contract) => $this->actBy($contract) ?? '9999-12-31')
            ->map(fn (Contract $contract) => [
                $contract->reference,
                $contract->title,
                $this->labelOf($contract->engagement, 'reference', 'Not linked'),
                $this->labelOf($contract->engagement?->thirdParty, 'legal_name', 'Not recorded'),
                ucwords(str_replace('_', ' ', (string) $contract->contract_type)),
                ucwords(str_replace('_', ' ', (string) $contract->status)),
                $contract->effective_date?->toDateString() ?? 'Not recorded',
                $contract->expiry_date?->toDateString() ?? 'Open-ended',
                ucwords(str_replace('_', ' ', (string) $contract->renewal_type)),
                $contract->notice_period_days_entity ?? 'Not recorded',
                $contract->noticeDeadline()?->toDateString() ?? 'None — no notice period recorded',
                $this->daysToAct($contract),
                $this->noticeWindowLabel($contract),
                $contract->blocking_gaps_count,
            ])
            ->values()
            ->all();
    }

    public function notes(): array
    {
        return [
            'Ordering' => 'By the earlier of the notice deadline and the expiry date',
            'Days to act' => 'Counted to the notice deadline where one exists, otherwise to expiry',
        ];
    }

    private function actBy(Contract $contract): ?string
    {
        $notice = $contract->noticeDeadline()?->toDateString();
        $expiry = $contract->expiry_date?->toDateString();

        return collect([$notice, $expiry])->filter()->min();
    }

    private function daysToAct(Contract $contract): int|string
    {
        $days = $contract->daysUntilNotice() ?? $contract->daysUntilExpiry();

        return $days ?? 'Open-ended';
    }

    private function noticeWindowLabel(Contract $contract): string
    {
        if ($contract->noticeDeadline() === null) {
            return 'No notice period recorded';
        }

        if ($contract->noticeWindowMissed()) {
            // The most expensive kind of true statement is "expires in 40
            // days" over a contract that silently renewed last month.
            return 'MISSED — this contract has auto-renewed';
        }

        // A passed deadline on a contract that does NOT auto-renew is a
        // different fact: the institution lost the chance to give notice
        // early, but nothing renewed behind it. `noticeWindowMissed()` is
        // deliberately false here, and reporting both as "missed" would put
        // the two on the same line in a report people act from.
        if ($contract->noticeDeadline()->isPast() && ! $contract->isEnded()) {
            return 'Deadline passed — no automatic renewal';
        }

        return 'Open';
    }
}
