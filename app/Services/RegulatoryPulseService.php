<?php

namespace App\Services;

use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use Carbon\CarbonImmutable;

/**
 * Regulatory pulse, driven entirely by the organisation's own
 * regulatory_circulars and regulatory_deadlines rows.
 *
 * The previous implementation returned a hardcoded array of six circulars —
 * complete with reference numbers, completion percentages and "actions
 * required" counts — regardless of what the tenant had actually recorded. A
 * Nigerian bank looking at that screen was reading a brochure, not its own
 * compliance position. Everything below is a query.
 */
class RegulatoryPulseService
{
    /**
     * Circulars issued within this many days count as "recent" for the
     * headline tile. Twelve weeks is a supervisory quarter.
     */
    private const RECENT_WINDOW_DAYS = 90;

    private const DEADLINE_HORIZON_DAYS = 30;

    /**
     * @return array<string,mixed>
     */
    public function pulse(int $orgId): array
    {
        $now = CarbonImmutable::now();

        $circulars = RegulatoryCircular::query()
            ->where('organization_id', $orgId)
            ->with('assignee')
            ->orderByDesc('date_issued')
            ->get();

        $deadlines = RegulatoryDeadline::query()
            ->where('organization_id', $orgId)
            ->with(['filings', 'responsible'])
            ->orderBy('deadline_date')
            ->get();

        $feed = $circulars->map(fn (RegulatoryCircular $c) => (object) [
            'id' => $c->id,
            'regulator' => $c->regulator,
            'title' => $c->title,
            'reference' => $c->circular_ref,
            'issued_at' => $c->date_issued?->toDateString(),
            'effective_at' => $c->effective_date?->toDateString(),
            'summary' => $c->summary,
            'impact' => strtolower((string) ($c->impact_level ?? 'medium')),
            'compliance_status' => $c->compliance_status ?? 'pending',
            'compliance_pct' => $c->compliance_pct !== null ? (float) $c->compliance_pct : null,
            'action_required' => $c->action_required,
            'assigned_to' => $c->assignee?->name,
            // How many register entries the tenant has actually linked to this
            // circular. Previously an invented "actions_required" integer.
            'linked_risks' => is_array($c->affected_risk_ids) ? count($c->affected_risk_ids) : 0,
            'linked_controls' => is_array($c->affected_control_ids) ? count($c->affected_control_ids) : 0,
            'is_overdue' => $c->effective_date !== null
                && $c->effective_date->isPast()
                && strtolower((string) $c->compliance_status) !== 'compliant',
        ])->values();

        $upcoming = $deadlines
            ->filter(fn (RegulatoryDeadline $d) => $d->deadline_date !== null
                && $d->deadline_date->betweenIncluded($now, $now->addDays(self::DEADLINE_HORIZON_DAYS))
                && ! in_array($d->status, ['submitted', 'not_applicable'], true))
            ->map(fn (RegulatoryDeadline $d) => (object) [
                'title' => $d->title,
                'regulator' => $d->regulator,
                'report_type' => $d->report_type,
                'due_date' => $d->deadline_date->toDateString(),
                'due_display' => $d->deadline_date->format('d M Y'),
                'days_remaining' => (int) $now->startOfDay()->diffInDays($d->deadline_date->startOfDay(), false),
                'responsible' => $d->responsible?->name,
                'is_urgent' => $d->deadline_date->lessThanOrEqualTo($now->addDays(7)),
            ])
            ->values();

        $overdueDeadlines = $deadlines->filter(fn (RegulatoryDeadline $d) => $d->isOverdue())->values();

        // Impact mix across the circulars actually on file. No synthetic
        // buckets: a level with no circulars reads zero.
        $impactMix = [];
        foreach (['critical', 'high', 'medium', 'low'] as $level) {
            $impactMix[$level] = $feed->where('impact', $level)->count();
        }

        // Headline compliance figure: the mean recorded compliance_pct across
        // circulars that carry one. Null — not zero, and not a made-up 85 —
        // when nothing has been scored, so the screen can say "not recorded"
        // instead of implying total non-compliance.
        $scored = $circulars->filter(fn ($c) => $c->compliance_pct !== null);
        $meanCompliance = $scored->isNotEmpty()
            ? round((float) $scored->avg('compliance_pct'), 1)
            : null;

        return [
            'feed' => $feed,
            'upcoming_deadlines' => $upcoming,
            'impact_mix' => $impactMix,
            'summary' => [
                'circulars_total' => $feed->count(),
                'circulars_recent' => $circulars
                    ->filter(fn ($c) => $c->date_issued !== null
                        && $c->date_issued->greaterThanOrEqualTo($now->subDays(self::RECENT_WINDOW_DAYS)))
                    ->count(),
                'high_impact' => $feed->whereIn('impact', ['critical', 'high'])->count(),
                'past_effective_date_not_compliant' => $feed->where('is_overdue', true)->count(),
                'mean_compliance_pct' => $meanCompliance,
                'scored_circulars' => $scored->count(),
                'deadlines_total' => $deadlines->count(),
                'deadlines_overdue' => $overdueDeadlines->count(),
                'deadlines_due_30d' => $upcoming->count(),
            ],
            'window' => [
                'recent_window_days' => self::RECENT_WINDOW_DAYS,
                'deadline_horizon_days' => self::DEADLINE_HORIZON_DAYS,
            ],
            'as_at' => $now->toDateTimeString(),
        ];
    }
}
