<?php

namespace App\Services\Widgets;

use App\Models\Period;
use App\Models\WidgetDefinition;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;

/**
 * WP-08 TASK 1 — turns a widget's period_binding into actual Period rows.
 *
 *   selected  the page's global period selector (WidgetContext::$period).
 *   relative  period_config {type, offset}: offset 0 = the period containing
 *             today, -1 = the one before it. Anchored to TODAY, not to the
 *             selector — "last month's number" on a wallboard means last
 *             month regardless of what somebody left selected.
 *   fixed     period_config {period_code}: one named period, forever.
 *   range     period_config {type, count}: a trailing window of `count`
 *             periods ENDING AT the selected period (falling back to today's)
 *             — the trend widgets' binding, and selector-aware on purpose so
 *             scrubbing the selector scrubs the whole trend.
 *
 * `selected` with nothing selected, or a fixed code that no longer exists,
 * resolves to no periods and the widget renders its no-data state; inventing
 * a window from raw dates would silently disagree with every other widget on
 * a fiscal-year tenant.
 */
class WidgetPeriodResolver
{
    public function __construct(private readonly PeriodService $periods) {}

    public function resolve(WidgetDefinition $definition, WidgetContext $context): ResolvedPeriods
    {
        $organizationId = $context->organizationId();

        return match ($definition->period_binding) {
            'relative' => $this->relative($definition, $organizationId),
            'fixed' => $this->fixed($definition, $organizationId),
            'range' => $this->range($definition, $context, $organizationId),
            default => new ResolvedPeriods($context->period, $context->period ? [$context->period] : []),
        };
    }

    private function relative(WidgetDefinition $definition, int $organizationId): ResolvedPeriods
    {
        $type = (string) $definition->periodConfig('type', 'month');
        $offset = (int) $definition->periodConfig('offset', 0);

        $period = $this->periods->resolve(CarbonImmutable::today(), $type, $organizationId);

        while ($period !== null && $offset < 0) {
            $period = $this->periods->previous($period);
            $offset++;
        }

        while ($period !== null && $offset > 0) {
            $period = $this->periods->next($period);
            $offset--;
        }

        return new ResolvedPeriods($period, $period ? [$period] : []);
    }

    private function fixed(WidgetDefinition $definition, int $organizationId): ResolvedPeriods
    {
        $code = $definition->periodConfig('period_code');

        $period = $code === null ? null : Period::query()
            ->where('organization_id', $organizationId)
            ->where('code', (string) $code)
            ->first();

        return new ResolvedPeriods($period, $period ? [$period] : []);
    }

    private function range(WidgetDefinition $definition, WidgetContext $context, int $organizationId): ResolvedPeriods
    {
        $type = (string) $definition->periodConfig('type', 'month');
        $count = max(1, (int) $definition->periodConfig('count', 12));

        // The window ends at the selected period where one is selected, at
        // today's otherwise. trailing() filters on end_date, so a selected
        // quarter correctly ends a monthly window at that quarter's last month.
        $end = $context->period ?? $this->periods->resolve(CarbonImmutable::today(), $type, $organizationId);

        $window = $this->periods->trailing($end, $count, $type);

        return new ResolvedPeriods($end, $window->values()->all());
    }
}
