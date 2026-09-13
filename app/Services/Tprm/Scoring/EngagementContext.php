<?php

namespace App\Services\Tprm\Scoring;

use App\Models\Tprm\Engagement;
use App\Support\Tprm\FactRegistry;

/**
 * Builds the flat fact context the knockout rules and questionnaire visibility
 * rules are evaluated against.
 *
 * THIS IS THE ONLY PLACE THAT TOUCHES THE DATABASE ON THE SCORING PATH. The
 * calculators are pure and take a context; this assembles one. Keeping the
 * boundary here means a knockout can be tested against a plain array, and it
 * means the queries are in one reviewable place rather than scattered through
 * the rules.
 *
 * The derived facts — `has_core_banking_connection`, `max_function_criticality`
 * and the rest — are the ones `FactRegistry::DERIVED` declares. They need a
 * query, so they cannot be read off a column, and every one of them is
 * computed here rather than invented at each call site under a slightly
 * different name.
 */
class EngagementContext
{
    /**
     * @param  array<string, mixed>  $answers  Appendix A answers, keyed A1…A18
     * @return array<string, mixed>
     */
    public function build(Engagement $engagement, array $answers = []): array
    {
        $engagement->loadMissing(['thirdParty', 'businessFunctions']);

        return array_merge(
            FactRegistry::extract($engagement, FactRegistry::ENGAGEMENT),
            FactRegistry::extract($engagement->thirdParty, FactRegistry::THIRD_PARTY),
            FactRegistry::answers($answers),
            $this->derived($engagement, $answers),
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function derived(Engagement $engagement, array $answers): array
    {
        $functions = $engagement->businessFunctions;

        // FR-TIER-06: the engagement inherits the HIGHEST criticality of any
        // function it supports. Ordering by the enum's own ranking rather than
        // alphabetically, which would make "important" beat "critical".
        $order = ['standard' => 0, 'important' => 1, 'critical' => 2];
        $maxCriticality = null;
        $minRto = null;

        foreach ($functions as $function) {
            $rank = $order[$function->criticality] ?? 0;

            if ($maxCriticality === null || $rank > ($order[$maxCriticality] ?? -1)) {
                $maxCriticality = $function->criticality;
            }

            if ($function->rto_hours !== null && ($minRto === null || $function->rto_hours < $minRto)) {
                $minRto = (int) $function->rto_hours;
            }
        }

        $thirdParty = $engagement->thirdParty;

        return [
            'engagement.max_function_criticality' => $maxCriticality,

            // Null RTO would make `lte 4` unresolvable and KO-CIF silently not
            // fire. A function with no stated RTO is treated as the widest
            // tolerance rather than the tightest — the knockout should need
            // positive evidence of a tight recovery objective, not the absence
            // of an answer.
            'engagement.min_function_rto_hours' => $minRto ?? PHP_INT_MAX,

            'engagement.has_prohibited_function' => $functions->contains(
                fn ($function) => (bool) $function->is_prohibited_outsourcing
            ),

            // Answered at intake before any connection record exists, so the
            // questionnaire answer is authoritative until the engagement is
            // live and `tp_connections` takes over.
            'engagement.has_core_banking_connection' => $this->truthy($answers['A5'] ?? null, 'privileged')
                || $engagement->connectionsToCoreBanking(),

            'engagement.has_privileged_access' => $this->truthy($answers['A5'] ?? null, 'privileged')
                || $engagement->hasPrivilegedAccessGrant(),

            'engagement.has_executed_contract' => $engagement->hasExecutedContract(),

            'engagement.open_critical_findings' => 0,
            'engagement.open_high_findings' => 0,
            'engagement.expired_evidence_count' => 0,
            'engagement.assessment_overdue_days' => 0,
            'engagement.exit_plan_status' => null,

            'third_party.has_pep_owner' => (bool) $thirdParty?->ownership()->where('is_pep', true)->exists(),
            'third_party.has_true_match' => (bool) $thirdParty?->hasConfirmedSanctionsMatch(),
        ];
    }

    private function truthy(mixed $value, string $match): bool
    {
        return is_string($value) && $value === $match;
    }
}
