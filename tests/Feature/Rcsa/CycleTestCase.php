<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;

/**
 * Fixtures for the cycle and workspace tests: the universe helpers of
 * UniverseTestCase, plus a published risk builder and a cycle builder.
 */
abstract class CycleTestCase extends UniverseTestCase
{
    /** @var list<string> */
    protected const CYCLE_PERMISSIONS = [
        'rcsa_cycle.view',
        'rcsa_cycle.manage',
        'rcsa_cycle.open',
        'rcsa_cycle.close',
        'rcsa_assessment.view',
        'rcsa_assessment.complete',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(self::CYCLE_PERMISSIONS);
    }

    protected function methodology(): RcsaMethodology
    {
        return RcsaMethodology::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', Template::CODE)
            ->with(['scaleItems', 'bands'])
            ->firstOrFail();
    }

    /**
     * A PUBLISHED universe risk — the only kind a cycle provisions.
     */
    protected function publishedRisk(array $overrides = []): RcsaRegisterRisk
    {
        return $this->makeRisk(array_merge([
            'status' => RcsaRegisterRisk::PUBLISHED,
            'published_at' => now(),
        ], $overrides));
    }

    protected function makeCycle(array $overrides = []): RcsaCycle
    {
        return RcsaCycle::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'RCSA 2026 H1',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'due_date' => '2026-07-15',
            'methodology_id' => $this->methodology()->id,
            'status' => RcsaCycle::DRAFT,
        ], $overrides));
    }

    /**
     * A cycle with one published risk in Retail, opened.
     */
    protected function openedCycle(array $cycleOverrides = []): RcsaCycle
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $cycle = $this->makeCycle($cycleOverrides);

        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        return $cycle->refresh();
    }
}
