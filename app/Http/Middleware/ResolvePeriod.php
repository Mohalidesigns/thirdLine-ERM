<?php

namespace App\Http\Middleware;

use App\Models\Period;
use App\Services\PeriodService;
use App\Support\Periods\PeriodContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Binds the selected reporting period for the request and exposes it to every
 * view as $selectedPeriod.
 *
 * Precedence is query param, then session, then "the period containing today".
 * The query param is what the ‹ › arrows and the period dropdown submit; it is
 * written back to the session so that following a link to another screen keeps
 * the period the user was looking at, which is the behaviour the Corporater
 * chrome has and the reason the selector lives in the top bar rather than on
 * each page.
 *
 * Runs after ResolveTenant — a period belongs to an organisation, and a period
 * id from another tenant is ignored rather than honoured.
 *
 * Failure here is never fatal. A tenant whose calendar cannot be provisioned
 * gets a null $selectedPeriod and screens behave as they did before WP-04,
 * rather than the whole application 500ing on a date.
 */
class ResolvePeriod
{
    /** Session key holding the id of the period the user last selected. */
    public const SESSION_KEY = 'selected_period_id';

    /** Session key holding the granularity the selector is showing. */
    public const SESSION_TYPE_KEY = 'selected_period_type';

    public function __construct(private PeriodService $periods) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (TenantContext::organizationIdOrNull() === null) {
            PeriodContext::use(null);
            View::share('selectedPeriod', null);

            return $next($request);
        }

        $period = $this->resolve($request);

        PeriodContext::use($period);
        View::share('selectedPeriod', $period);
        View::share('selectedPeriodType', $period?->type ?? $request->session()->get(self::SESSION_TYPE_KEY, 'month'));

        return $next($request);
    }

    private function resolve(Request $request): ?Period
    {
        $organizationId = TenantContext::organizationId();

        try {
            $requested = $request->query('period');

            if ($requested !== null && $requested !== '') {
                $period = $this->lookup((string) $requested, $organizationId);

                if ($period !== null) {
                    $request->session()->put(self::SESSION_KEY, $period->id);
                    $request->session()->put(self::SESSION_TYPE_KEY, $period->type);

                    return $period;
                }
            }

            $remembered = $request->session()->get(self::SESSION_KEY);

            if ($remembered !== null) {
                $period = $this->periods->byId((int) $remembered, $organizationId);

                if ($period !== null) {
                    return $period;
                }

                // Stale — the period was deleted, or the session survived a
                // move between tenants. Fall through to the default rather
                // than showing another organisation's calendar.
                $request->session()->forget(self::SESSION_KEY);
            }

            $default = $this->periods->current(
                $request->session()->get(self::SESSION_TYPE_KEY, 'month'),
                $organizationId
            );

            $request->session()->put(self::SESSION_KEY, $default->id);

            return $default;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A period by id or by code — the arrows submit ids, a deep link from a
     * report is more readable as ?period=FY2026-Q1.
     */
    private function lookup(string $requested, int $organizationId): ?Period
    {
        if (ctype_digit($requested)) {
            return $this->periods->byId((int) $requested, $organizationId);
        }

        return Period::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $requested)
            ->first();
    }
}
