<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\StoreReportScheduleRequest;
use App\Models\Tprm\ReportSchedule;
use App\Services\Tprm\Reporting\Operational\OperationalReport;
use App\Services\Tprm\Reporting\Operational\OperationalReportRegistry;
use App\Services\Tprm\Reporting\ScheduledReportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Standing report schedules — FR-RPT-09.
 *
 * THE LIST SHOWS EVERY SCHEDULE, INCLUDING ONES READING DATA YOU CANNOT SEE.
 * That looks like the opposite of the hub's rule and is deliberate: a schedule
 * is a standing instruction to email data out of the institution, and somebody
 * reviewing the estate needs to see that a screening log goes to an external
 * mailbox every Monday even if they cannot open it themselves. The schedule's
 * NAME and RECIPIENTS are governance facts; its CONTENTS are not shown here.
 *
 * `runNow` EXISTS BECAUSE A SCHEDULE NOBODY HAS TESTED IS A GUESS. It sends
 * the real report to the real recipients rather than to the caller — a
 * "test to me" button would prove the render and not the distribution list,
 * which is the half that is usually wrong.
 */
class ReportScheduleController extends Controller
{
    public function __construct(
        private readonly OperationalReportRegistry $registry,
        private readonly ScheduledReportDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $schedules = ReportSchedule::query()
            ->with('owner:id,name')
            ->orderBy('name')
            ->get();

        $titles = collect($this->registry->all())
            ->mapWithKeys(fn (OperationalReport $report) => [$report->key() => $report->title()]);

        return Inertia::render('Tprm/Reports/Schedules', [
            'schedules' => $schedules->map(fn (ReportSchedule $schedule) => [
                'uuid' => $schedule->uuid,
                'name' => $schedule->name,
                'report_key' => $schedule->report_key,
                'report_title' => $titles[$schedule->report_key] ?? 'Withdrawn report',
                'frequency' => $schedule->describeFrequency(),
                'format' => strtoupper($schedule->format),
                'recipients' => $schedule->recipientList(),
                'owner' => $schedule->owner?->name,
                'is_active' => $schedule->is_active,
                'last_run' => $schedule->describeLastRun(),
                'last_run_status' => $schedule->last_run_status,
                'consecutive_failures' => $schedule->consecutive_failures,
            ]),
            // Only the reports this user may read can be scheduled BY them.
            'available' => array_map(fn (OperationalReport $report) => [
                'key' => $report->key(),
                'title' => $report->title(),
            ], $this->registry->forUser($request->user())),
            'formats' => ReportSchedule::FORMATS,
            'frequencies' => ReportSchedule::FREQUENCIES,
            'maxDayOfMonth' => ReportSchedule::MAX_DAY_OF_MONTH,
            // The go-live gap, said on the screen rather than only in a log.
            'mailerIsLog' => config('mail.default') === 'log',
            'can' => [
                'manage' => $request->user()->can('tprm.report.export'),
            ],
        ]);
    }

    public function store(StoreReportScheduleRequest $request)
    {
        ReportSchedule::create($request->validated() + [
            'organization_id' => TenantContext::organizationId(),
            'owner_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'The schedule is set. It will send on its next due day.');
    }

    public function update(StoreReportScheduleRequest $request, ReportSchedule $reportSchedule)
    {
        $reportSchedule->update($request->validated() + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Schedule updated.');
    }

    public function destroy(Request $request, ReportSchedule $reportSchedule)
    {
        Gate::authorize('tprm.report.export');

        $reportSchedule->delete();

        return back()->with('success', 'Schedule removed. Nothing further will be sent from it.');
    }

    /**
     * Send it now, to its real recipients.
     */
    public function runNow(Request $request, ReportSchedule $reportSchedule)
    {
        Gate::authorize('tprm.report.export');

        $outcome = $this->dispatcher->run($reportSchedule);

        $reportSchedule->refresh();

        return back()->with(
            $outcome === 'sent' ? 'success' : 'error',
            match ($outcome) {
                'sent' => 'Sent to '.count($reportSchedule->recipientList()).' recipients.',
                'skipped' => 'Not sent: '.$reportSchedule->last_run_message,
                default => 'The send failed: '.$reportSchedule->last_run_message,
            },
        );
    }
}
