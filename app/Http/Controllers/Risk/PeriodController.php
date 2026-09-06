<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolvePeriod;
use App\Http\Requests\Periods\ReopenPeriodRequest;
use App\Http\Requests\Periods\SelectPeriodRequest;
use App\Models\MeasureValue;
use App\Models\Period;
use App\Services\PeriodService;
use App\Services\ThresholdRebaselineService;
use App\Support\Periods\PeriodContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The reporting calendar: selecting a period, and closing one.
 *
 * The selector is the piece every other screen depends on — a dashboard, a
 * register and a board pack are all "as at" views, and until WP-04 the only
 * available answer was "now".
 */
class PeriodController extends Controller
{
    public function __construct(private PeriodService $periods) {}

    /**
     * Change the period bound to the session and return where the user was.
     *
     * Accepts an id or a code, and a direction, so the ‹ › arrows in the top
     * bar are a single link each rather than needing to know what the
     * neighbouring period is.
     */
    public function select(SelectPeriodRequest $request)
    {
        $validated = $request->validated();

        $organizationId = TenantContext::organizationId();
        $current = PeriodContext::current();
        $target = null;

        if (! empty($validated['period'])) {
            $target = Period::query()
                ->where('organization_id', $organizationId)
                ->where(fn ($query) => $query->where('id', $validated['period'])->orWhere('code', $validated['period']))
                ->first();
        } elseif (! empty($validated['type'])) {
            // Switching granularity keeps the user where they are in time: the
            // quarter containing the month they were looking at.
            $target = $this->periods->resolve(
                $current?->start_date ?? now(),
                $validated['type'],
                $organizationId
            );
        } elseif (! empty($validated['direction']) && $current !== null) {
            // The rule restricts direction to these three, but the validated
            // array is mixed as far as static analysis is concerned, and an
            // unmatched match() throws \UnhandledMatchError — a 500 rather
            // than the "nothing to move to" the caller should get.
            $target = match ((string) $validated['direction']) {
                'previous' => $this->periods->previous($current),
                'next' => $this->periods->next($current),
                'current' => $this->periods->current($current->type, $organizationId),
                default => null,
            };
        }

        if ($target === null) {
            return $this->backTo($validated['redirect'] ?? null)
                ->with('error', 'That reporting period could not be found.');
        }

        $request->session()->put(ResolvePeriod::SESSION_KEY, $target->id);
        $request->session()->put(ResolvePeriod::SESSION_TYPE_KEY, $target->type);

        return $this->backTo($validated['redirect'] ?? null);
    }

    /**
     * The calendar: every period, what is closed, and how much is locked
     * behind it.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Period::class);

        $organizationId = TenantContext::organizationId();
        $calendar = $this->periods->ensureCalendar($organizationId);

        $type = $request->input('type', 'quarter');

        $periods = Period::query()
            ->where('calendar_id', $calendar->id)
            ->where('type', $type)
            ->with('closedBy')
            ->orderByDesc('start_date')
            ->paginate(24)
            ->withQueryString();

        $valueCounts = MeasureValue::query()
            ->whereIn('period_id', $periods->pluck('id'))
            ->selectRaw('period_id, count(*) as total')
            ->groupBy('period_id')
            ->pluck('total', 'period_id');

        // The Blade table drew a "selected" badge from $selectedPeriod, which
        // the controller never passed — so it never appeared. The selected
        // period is the one bound to the session.
        $selectedId = PeriodContext::current()?->id;

        return Inertia::render('Periods/Index', [
            'calendar' => [
                'name' => $calendar->name,
                'fiscalYearStartMonth' => $calendar->fiscal_year_start_month,
                'fiscalYearStartLabel' => \Carbon\Carbon::create(null, $calendar->fiscal_year_start_month, 1)->format('F'),
            ],
            'type' => $type,
            'types' => ['month' => 'Months', 'quarter' => 'Quarters', 'half' => 'Halves', 'year' => 'Years'],
            'periods' => [
                'data' => collect($periods->items())->map(fn (Period $period) => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'code' => $period->code,
                    'startDate' => $period->start_date?->format('d M Y'),
                    'endDate' => $period->end_date?->format('d M Y'),
                    'values' => (int) ($valueCounts[$period->id] ?? 0),
                    'isClosed' => (bool) $period->is_closed,
                    'closedAt' => $period->closed_at?->format('d M Y'),
                    'closedBy' => $period->closedBy?->name,
                    'isSelected' => $period->id === $selectedId,
                    'selectUrl' => route('risk.periods.select', ['period' => $period->id, 'redirect' => '/risk/periods']),
                    'canClose' => Gate::allows('close', $period),
                    'canReopen' => Gate::allows('reopen', $period),
                ])->all(),
                'links' => $periods->linkCollection()->toArray(),
                'meta' => ['from' => $periods->firstItem(), 'to' => $periods->lastItem(), 'total' => $periods->total()],
            ],
        ]);
    }

    /**
     * Close a period. Locks its values and those of every period beneath it,
     * then re-evaluates formula thresholds against the closed figures.
     */
    public function close(Request $request, Period $period, ThresholdRebaselineService $rebaseline)
    {
        Gate::authorize('close', $period);

        if ($period->is_closed) {
            return back()->with('error', "{$period->name} is already closed.");
        }

        $locked = $this->periods->close($period, $request->user());

        // Re-baselining runs at close because that is the moment the period's
        // denominators — capital, revenue, CPI — are final. It raises approval
        // tasks; it does not move a limit on its own.
        $review = $rebaseline->review($period->fresh());

        $message = "{$period->name} closed. {$locked} value(s) locked.";

        if ($review['raised'] > 0) {
            $message .= " {$review['raised']} threshold re-baselining approval(s) raised.";
        }

        return back()->with('success', $message);
    }

    /**
     * Reopen a closed period.
     *
     * A reason is mandatory: this is the one operation that can change a number
     * a board pack has already been built on.
     */
    public function reopen(ReopenPeriodRequest $request, Period $period)
    {
        $validated = $request->validated();

        if (! $period->is_closed) {
            return back()->with('error', "{$period->name} is not closed.");
        }

        $unlocked = $this->periods->reopen($period, $request->user(), $validated['reason']);

        return back()->with('success', "{$period->name} reopened. {$unlocked} value(s) unlocked.");
    }

    /**
     * Redirect back, honouring an explicit target but never an off-site one.
     */
    private function backTo(?string $redirect)
    {
        if ($redirect === null || $redirect === '') {
            return back();
        }

        // A period link carries the page it came from. Only a path on this
        // application is followed — an absolute URL in a query string is an
        // open redirect waiting to happen.
        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return back();
        }

        return redirect()->to($redirect);
    }
}
