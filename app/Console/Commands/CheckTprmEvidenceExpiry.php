<?php

namespace App\Console\Commands;

use App\Models\Tprm\Document;
use App\Services\NotificationService;
use App\Services\Tprm\Evidence\ExpiryMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The daily evidence-expiry sweep — FR-EVD-02.
 *
 * NOTICES FIRE AT EXACTLY T-90, T-60, T-30 AND T-7, and that exactness is what
 * makes the command idempotent without a "last notified" column, the same
 * reasoning as `CheckRcsaActionPlans`. A certificate 45 days out matches
 * nothing; the day it is 30 days out it matches once. Run the sweep twice and
 * a notice is duplicated, which is a nuisance; make the windows cumulative and
 * every document notifies every day for ninety days, which is how a channel
 * gets muted — and a muted channel is worse than none, because everyone
 * believes it is working.
 *
 * ONE DOCUMENT MUST NOT TAKE THE SWEEP DOWN WITH IT. Each notice is sent in
 * its own try/catch: the lesson of `CheckOverdueTreatments`, where a throw on
 * the first row meant the nightly job processed exactly one record and died.
 *
 * IT RUNS WITHOUT TENANCY. There is no authenticated user in a scheduled
 * command, so the organisation scope has nothing to scope to; the queries are
 * deliberately unscoped and each document carries its own `organization_id`
 * into the notification.
 */
class CheckTprmEvidenceExpiry extends Command
{
    protected $signature = 'tprm:check-evidence-expiry {--dry-run : Report what would be sent without notifying}';

    protected $description = 'Notify relationship owners of third-party evidence expiring at 90, 60, 30 and 7 days';

    public function handle(ExpiryMonitor $monitor): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $notices = $this->sendNotices($monitor, $dryRun);
        $expired = $this->announceExpired($monitor, $dryRun);

        $this->info(sprintf(
            '%s%d expiry notice(s) and %d expiry announcement(s) sent.',
            $dryRun ? '[dry run] ' : '',
            $notices,
            $expired,
        ));

        return self::SUCCESS;
    }

    private function sendNotices(ExpiryMonitor $monitor, bool $dryRun): int
    {
        $sent = 0;

        foreach ($monitor->due() as $entry) {
            /** @var Document $document */
            $document = $entry['document'];
            $window = $entry['window'];

            $recipients = $monitor->recipientsFor($document);

            if ($recipients === []) {
                // Reported rather than skipped silently: evidence nobody owns
                // is the state the expiry monitor exists to make visible, and
                // a document that notifies nobody is exactly the one that will
                // lapse.
                $this->warn(sprintf(
                    'Document #%d (%s) expires in %d days and has no relationship owner or uploader to notify.',
                    $document->getKey(),
                    Str::limit($document->title, 60),
                    $window,
                ));

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would notify %d user(s) that "%s" expires in %d days.',
                    count($recipients),
                    Str::limit($document->title, 60),
                    $window,
                ));
                $sent += count($recipients);

                continue;
            }

            foreach ($recipients as $userId) {
                try {
                    NotificationService::send(
                        organizationId: (int) $document->organization_id,
                        userId: $userId,
                        type: 'tprm.evidence.expiring',
                        subject: sprintf('Expires in %d days: %s', $window, Str::limit($document->title, 80)),
                        body: sprintf(
                            '%s expires on %s. Once it lapses, the controls it evidences fall back to the '
                            .'assurance level of whatever else supports them, and the engagement\'s score will '
                            .'move accordingly.',
                            $document->title,
                            $document->valid_to?->toDateString(),
                        ),
                        metadata: [
                            'document_id' => $document->getKey(),
                            'owner_type' => $document->owner_type,
                            'owner_id' => $document->owner_id,
                            'days_remaining' => $window,
                        ],
                        actionUrl: route('tprm.documents.show', $document->uuid, absolute: false),
                        // 7 days is the last notice before the score moves.
                        priority: $window <= 7 ? 'high' : 'medium',
                        category: 'workflow',
                    );

                    $sent++;
                } catch (\Throwable $exception) {
                    logger()->error('TPRM evidence expiry notice failed', [
                        'document_id' => $document->getKey(),
                        'user_id' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $sent;
    }

    /**
     * A different message to the same reader: not "please chase this" but
     * "this has lapsed and the score has already moved".
     */
    private function announceExpired(ExpiryMonitor $monitor, bool $dryRun): int
    {
        $sent = 0;

        foreach ($monitor->newlyExpired() as $document) {
            $recipients = $monitor->recipientsFor($document);

            if ($dryRun) {
                $sent += count($recipients);

                continue;
            }

            foreach ($recipients as $userId) {
                try {
                    NotificationService::send(
                        organizationId: (int) $document->organization_id,
                        userId: $userId,
                        type: 'tprm.evidence.expired',
                        subject: sprintf('Expired: %s', Str::limit($document->title, 80)),
                        body: sprintf(
                            '%s expired on %s and has not been replaced. Any control relying on it is no longer '
                            .'independently assured.',
                            $document->title,
                            $document->valid_to?->toDateString(),
                        ),
                        metadata: [
                            'document_id' => $document->getKey(),
                            'owner_type' => $document->owner_type,
                            'owner_id' => $document->owner_id,
                        ],
                        actionUrl: route('tprm.documents.show', $document->uuid, absolute: false),
                        priority: 'high',
                        category: 'workflow',
                    );

                    $sent++;
                } catch (\Throwable $exception) {
                    logger()->error('TPRM evidence expiry announcement failed', [
                        'document_id' => $document->getKey(),
                        'user_id' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $sent;
    }
}
