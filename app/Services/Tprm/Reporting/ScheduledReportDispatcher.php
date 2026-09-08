<?php

namespace App\Services\Tprm\Reporting;

use App\Mail\Tprm\ScheduledReport;
use App\Models\Organization;
use App\Models\Tprm\ReportSchedule;
use App\Services\Tprm\Reporting\Operational\OperationalReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ThirdLine\Platform\Tenancy\TenantContext;
use ThirdLine\Reporting\DocumentRenderer;
use Throwable;

/**
 * Renders due schedules and emails them — FR-RPT-09.
 *
 * EVERY SEND IS AUTHORISED AGAINST THE SCHEDULE OWNER, NOT THE RECIPIENTS.
 * Recipients are email addresses — a procurement mailbox, an outsourced
 * company secretary — and are not users of this system, so they have no
 * permissions to check. What must not happen is a schedule outliving the
 * person who made it and continuing to email a screening log after they left.
 * A schedule whose owner is gone, or who has lost the report's permission, is
 * SKIPPED and says so, rather than falling back to nobody's permissions, which
 * would be everybody's.
 *
 * A FAILURE IS RECORDED ON THE ROW AND THE RUN CONTINUES. One schedule with a
 * bad recipient must not stop the other eleven, and the outcome has to be
 * visible on the screen afterwards — a schedule that fails silently is worse
 * than no schedule, because everybody believes it is running.
 *
 * `MAIL_MAILER` DEFAULTS TO `log` ON THIS INSTALLATION. That is a deployment
 * gap, not a defect here: the dispatcher records a successful send because the
 * mailer accepted the message, and the log driver does accept it. Nobody
 * should read a green run as proof an email arrived until production SMTP is
 * configured.
 */
class ScheduledReportDispatcher
{
    public function __construct(
        private readonly OperationalReportRegistry $registry,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * Run every due schedule across every tenant.
     *
     * @return array{considered: int, sent: int, skipped: int, failed: int}
     */
    public function dispatchDue(?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();

        $totals = ['considered' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (Organization::query()->where('is_active', true)->pluck('id') as $organizationId) {
            TenantContext::actingAs((int) $organizationId, function () use ($on, &$totals) {
                foreach (ReportSchedule::query()->active()->get() as $schedule) {
                    if (! $schedule->isDueOn($on)) {
                        continue;
                    }

                    $totals['considered']++;
                    $outcome = $this->run($schedule, $on);
                    $totals[$outcome]++;
                }
            });
        }

        return $totals;
    }

    /**
     * @return 'sent'|'skipped'|'failed'
     */
    public function run(ReportSchedule $schedule, ?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::now();

        try {
            $refusal = $this->refusalReason($schedule);

            if ($refusal !== null) {
                $this->record($schedule, ReportSchedule::STATUS_SKIPPED, $refusal, $on);

                return 'skipped';
            }

            $report = $this->registry->find($schedule->report_key);
            $rows = $report->rows();

            $provenance = new ReportProvenance(
                title: $report->title(),
                asAt: $on,
                // The owner, not "the system". A recipient who does not
                // recognise the report needs somebody to ask.
                preparedBy: $schedule->owner->name.' (scheduled)',
                filters: $report->notes(),
                rowCount: count($rows),
                versions: ['Scoring engine version' => (string) config('tprm.engine_version')],
                authority: 'FR-RPT-07 — '.$report->description(),
            );

            $document = $this->render($schedule, $report->headers(), $rows, $provenance);

            Mail::to($schedule->recipientList())->send(new ScheduledReport(
                reportTitle: $report->title(),
                scheduleName: $schedule->name,
                organizationName: $this->organizationName($schedule),
                ownerName: $schedule->owner->name,
                asAt: $on->toFormattedDateString(),
                rowCount: count($rows),
                provenance: $provenance->filterProvenance(),
                fileContents: $document['content'],
                fileName: 'tprm-'.$report->key().'-'.$on->format('Y-m-d').'.'.$document['extension'],
                mimeType: $document['mime'],
            ));

            $this->record(
                $schedule,
                ReportSchedule::STATUS_SUCCEEDED,
                sprintf('%d rows to %d recipients', count($rows), count($schedule->recipientList())),
                $on,
            );

            return 'sent';
        } catch (Throwable $exception) {
            // One bad recipient must not stop the other eleven schedules.
            Log::error('Scheduled TPRM report failed', [
                'schedule' => $schedule->getKey(),
                'report' => $schedule->report_key,
                'message' => $exception->getMessage(),
            ]);

            $this->record($schedule, ReportSchedule::STATUS_FAILED, $exception->getMessage(), $on);

            return 'failed';
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Why this schedule must not send, or null.
     *
     * Each of these is a SKIP with a stated reason rather than a failure: none
     * is a fault in the schedule, and burning through the failure counter for
     * an owner who left would eventually hide a real error behind a
     * long-standing one.
     */
    private function refusalReason(ReportSchedule $schedule): ?string
    {
        if ($schedule->owner === null) {
            return 'The owner of this schedule no longer has an account, so there are no permissions to '
                .'authorise the send against.';
        }

        if ($schedule->recipientList() === []) {
            return 'No recipients are configured.';
        }

        try {
            $report = $this->registry->find($schedule->report_key);
        } catch (Throwable) {
            return "No report is registered under [{$schedule->report_key}]; it may have been withdrawn.";
        }

        if (! $schedule->owner->can($report->permission())) {
            // The schedule outlived the owner's access. It stops rather than
            // continuing to email data they may no longer see themselves.
            return $schedule->owner->name.' no longer holds '.$report->permission()
                .', which this report reads.';
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     * @return array{content: string, extension: string, mime: string}
     */
    private function render(ReportSchedule $schedule, array $headers, array $rows, ReportProvenance $provenance): array
    {
        $format = $this->renderer->normalise($schedule->format);

        if ($format === DocumentRenderer::FORMAT_PDF) {
            return [
                'content' => $this->renderer->pdf('reports.pdf.tprm-operational-report', [
                    'title' => $provenance->title,
                    'subtitle' => (string) $provenance->authority,
                    'organization' => $schedule->owner?->organization,
                    'periodAsAt' => $provenance->asAt,
                    'generatedBy' => $provenance->preparedBy,
                    'preparedBy' => $provenance->preparedBy,
                    'provenance' => $provenance->filterProvenance(),
                    'headers' => $headers,
                    'rows' => $rows,
                    'paper' => 'a3',
                    'orientation' => 'landscape',
                ]),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ];
        }

        return $this->renderer->render('reports.pdf.tprm-operational-report', [
            'headers' => $headers,
            'rows' => $rows,
            'sheet_name' => $provenance->title,
            'meta' => $provenance->toMeta(),
        ], $format);
    }

    private function organizationName(ReportSchedule $schedule): string
    {
        return Organization::query()->whereKey($schedule->organization_id)->value('name')
            ?? config('app.name');
    }

    private function record(ReportSchedule $schedule, string $status, string $message, CarbonImmutable $on): void
    {
        $schedule->forceFill([
            'last_run_at' => $on,
            'last_run_status' => $status,
            'last_run_message' => $message,
            'consecutive_failures' => $status === ReportSchedule::STATUS_FAILED
                ? $schedule->consecutive_failures + 1
                // A skip does not clear the counter either: the schedule has
                // still not delivered, and zeroing it would let a permanently
                // skipped schedule look healthy.
                : ($status === ReportSchedule::STATUS_SUCCEEDED ? 0 : $schedule->consecutive_failures),
        ])->save();
    }
}
