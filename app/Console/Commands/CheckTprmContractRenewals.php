<?php

namespace App\Console\Commands;

use App\Models\Tprm\Contract;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Renewal alerting keyed to the NOTICE PERIOD — FR-CTR-02.
 *
 * THIS IS THE COMMAND THAT SAVES A CLIENT FROM A CONTRACT THEY DECIDED TO
 * LEAVE. A contract expiring in ninety days with a hundred-and-twenty-day
 * notice period has already auto-renewed; an expiry-based reminder arrives to
 * tell somebody about a decision that is no longer theirs. So the milestones
 * are counted back from the last day notice can be served, not from the
 * expiry: 90, 60 and 30 days before the NOTICE deadline.
 *
 * MILESTONES FIRE ON EXACT DAYS, the same idempotency trick as the other
 * sweeps in this codebase — a contract 45 days from its notice deadline
 * matches nothing, and a second run in one day duplicates a notice rather than
 * inventing one.
 *
 * A MISSED NOTICE WINDOW IS ANNOUNCED ONCE AND LOUDLY. It is a different
 * message from the countdown: not "act by this date" but "this contract has
 * renewed and the next opportunity is a year away". A register that stayed
 * silent at that point would leave the institution believing it still had a
 * choice.
 */
class CheckTprmContractRenewals extends Command
{
    protected $signature = 'tprm:check-contract-renewals {--dry-run : Report what would be sent without notifying}';

    protected $description = 'Notify contract owners at 90, 60 and 30 days before the notice deadline, and when a notice window has been missed';

    /** @var list<int> */
    private const WINDOWS = [90, 60, 30];

    public function handle(): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $notices = $this->sendCountdowns($dryRun);
        $missed = $this->announceMissedWindows($dryRun);

        $this->info(sprintf(
            '%s%d renewal notice(s) and %d missed-window announcement(s).',
            $dryRun ? '[dry run] ' : '',
            $notices,
            $missed,
        ));

        return self::SUCCESS;
    }

    private function sendCountdowns(bool $dryRun): int
    {
        $sent = 0;

        foreach (self::WINDOWS as $window) {
            $contracts = Contract::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('status', Contract::STATUS_EXECUTED)
                ->whereNotNull('expiry_date')
                ->whereNotNull('notice_period_days_entity')
                // The exact-day match, computed in SQL from the notice date so
                // the driver difference lives in one expression.
                ->whereRaw(Contract::noticeDateExpression().' = ?', [now()->addDays($window)->toDateString()])
                ->with(['engagement.relationshipOwner:id,name', 'engagement.thirdParty:id,legal_name'])
                ->get();

            foreach ($contracts as $contract) {
                $sent += $this->notify(
                    $contract,
                    $dryRun,
                    'tprm.contract.renewal_notice',
                    sprintf('Notice deadline in %d days: %s', $window, Str::limit($contract->title, 70)),
                    sprintf(
                        'The last day to serve notice on %s (%s) is %s — %d days from now. The contract expires '
                        .'on %s, but the %d-day notice period means the decision has to be made and served '
                        .'before the deadline above, not before the expiry. %s',
                        $contract->engagement->thirdParty->legal_name ?? 'this provider',
                        $contract->reference,
                        $contract->noticeDeadline()?->toDateString(),
                        $window,
                        $contract->expiry_date?->toDateString(),
                        (int) $contract->notice_period_days_entity,
                        $contract->renewsAutomatically()
                            ? 'This contract renews automatically if notice is not served.'
                            : 'This contract does not renew automatically.',
                    ),
                    $window <= 30 ? 'high' : 'medium',
                );
            }
        }

        return $sent;
    }

    /**
     * Contracts whose notice window closed yesterday.
     *
     * Yesterday exactly, not "any time in the past": the announcement is made
     * once, on the day the opportunity is lost, for the same reason the
     * countdowns fire on exact days.
     */
    private function announceMissedWindows(bool $dryRun): int
    {
        $contracts = Contract::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', Contract::STATUS_EXECUTED)
            ->whereIn('renewal_type', ['auto', 'evergreen'])
            ->whereNotNull('expiry_date')
            ->whereNotNull('notice_period_days_entity')
            ->whereRaw(Contract::noticeDateExpression().' = ?', [now()->subDay()->toDateString()])
            ->with(['engagement.relationshipOwner:id,name', 'engagement.thirdParty:id,legal_name'])
            ->get();

        $sent = 0;

        foreach ($contracts as $contract) {
            $sent += $this->notify(
                $contract,
                $dryRun,
                'tprm.contract.notice_window_missed',
                sprintf('Notice window closed: %s', Str::limit($contract->title, 70)),
                sprintf(
                    'The notice period on %s (%s) closed yesterday, so the contract will renew on %s whether or '
                    .'not that was the intention. The next opportunity to serve notice is %s. If the '
                    .'relationship was to end, the exit now has to be negotiated rather than exercised.',
                    $contract->engagement->thirdParty->legal_name ?? 'this provider',
                    $contract->reference,
                    $contract->expiry_date?->toDateString(),
                    $contract->renewal_term_months
                        ? $contract->expiry_date?->copy()
                            ->addMonths((int) $contract->renewal_term_months)
                            ->subDays((int) $contract->notice_period_days_entity)->toDateString()
                        : 'the same point in the next term',
                ),
                'high',
            );
        }

        return $sent;
    }

    private function notify(Contract $contract, bool $dryRun, string $type, string $subject, string $body, string $priority): int
    {
        $recipients = array_values(array_unique(array_filter([
            $contract->engagement?->relationship_owner_id,
            $contract->internal_signatory_id,
        ])));

        if ($recipients === []) {
            $this->warn(sprintf(
                'Contract %s has no relationship owner or internal signatory to notify.',
                $contract->reference,
            ));

            return 0;
        }

        if ($dryRun) {
            $this->line(sprintf('Would notify %d user(s): %s', count($recipients), $subject));

            return count($recipients);
        }

        $sent = 0;

        foreach ($recipients as $userId) {
            try {
                NotificationService::send(
                    organizationId: (int) $contract->organization_id,
                    userId: $userId,
                    type: $type,
                    subject: $subject,
                    body: $body,
                    metadata: [
                        'contract_id' => $contract->getKey(),
                        'engagement_id' => $contract->engagement_id,
                        'notice_deadline' => $contract->noticeDeadline()?->toDateString(),
                        'expiry_date' => $contract->expiry_date?->toDateString(),
                    ],
                    actionUrl: route('tprm.contracts.show', $contract->getKey(), absolute: false),
                    priority: $priority,
                    category: 'workflow',
                );

                $sent++;
            } catch (\Throwable $exception) {
                logger()->error('TPRM renewal notice failed', [
                    'contract_id' => $contract->getKey(),
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
