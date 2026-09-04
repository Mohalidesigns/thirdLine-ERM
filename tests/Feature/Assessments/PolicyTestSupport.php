<?php

namespace Tests\Feature\Assessments;

use App\Models\RiskAssessment;
use App\Support\Tenancy\TenantContext;

/**
 * The cross-tenant assessment fixture, separated so the policy test reads as
 * assertions rather than as setup.
 */
abstract class PolicyTestSupport extends AssessmentsTestCase
{
    protected function foreignAssessment(): RiskAssessment
    {
        return TenantContext::bypass(fn () => RiskAssessment::create([
            'organization_id' => $this->otherOrg->id,
            'risk_id' => $this->foreignRisk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => '2026-06-30',
            'assessor_id' => $this->otherActor->id,
            'status' => 'in_review',
        ]), 'test fixture');
    }
}
