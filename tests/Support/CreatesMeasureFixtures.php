<?php

namespace Tests\Support;

use App\Models\KeyRiskIndicator;
use App\Models\Measure;
use App\Models\MeasureThreshold;
use App\Models\Period;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Support\Measures\MeasureCatalog;
use App\Support\Measures\UnitRegistry;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Builders for the WP-04 measure engine.
 *
 * Deliberately thin: every test that asserts on a number supplies that number
 * itself. Nothing here defaults a value, a threshold or a rate — a fixture that
 * invents a figure produces a test that passes for the wrong reason.
 */
trait CreatesMeasureFixtures
{
    protected function periods(): PeriodService
    {
        return app(PeriodService::class);
    }

    protected function measures(): MeasureService
    {
        return app(MeasureService::class);
    }

    /** The organisation's calendar, its periods, and the product measure catalogue. */
    protected function bootMeasureEngine(?int $organizationId = null): void
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        UnitRegistry::install();
        $this->periods()->ensureCalendar($organizationId);
        MeasureCatalog::install($organizationId);
    }

    protected function quarter(string $date, ?int $organizationId = null): Period
    {
        return $this->periods()->resolve($date, 'quarter', $organizationId);
    }

    protected function month(string $date, ?int $organizationId = null): Period
    {
        return $this->periods()->resolve($date, 'month', $organizationId);
    }

    /**
     * An approved assessment carrying the scores given.
     *
     * Dispatches AssessmentApproved rather than calling the recorder directly,
     * so the test exercises the whole approval path: the denormalised columns
     * on `risks` and the period-stamped copy in measure_values are both
     * written, by the same listeners the controller relies on.
     */
    protected function approveAssessment(Risk $risk, string $assessmentDate, array $scores = []): RiskAssessment
    {
        $assessment = RiskAssessment::create(array_merge([
            'organization_id' => $risk->organization_id,
            'risk_id' => $risk->id,
            'assessment_date' => $assessmentDate,
            'assessment_type' => 'periodic',
            'assessor_id' => $this->actor->id,
            'status' => 'approved',
        ], $scores));

        \App\Events\AssessmentApproved::dispatch($assessment, $risk->fresh());

        return $assessment;
    }

    /**
     * A KRI with the three-number threshold shape the KRI screens use.
     */
    protected function makeKri(array $attributes = []): KeyRiskIndicator
    {
        static $sequence = 0;
        $n = ++$sequence;

        $kri = KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Indicator '.$n,
            'kri_name' => 'Indicator '.$n,
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'pct',
            'measurement_unit' => 'pct',
            'threshold_direction' => 'higher_worse',
            'direction' => 'higher_is_worse',
            'is_active' => true,
            'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ], $attributes));

        app(\App\Services\KriMeasureBridge::class)->syncDefinition($kri->fresh());

        return $kri->fresh();
    }

    /**
     * Attach a band set to a measure, optionally with formula-valued bounds.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    protected function attachThreshold(
        Measure $measure,
        array $bands,
        string $effectiveFrom = '2020-01-01',
        string $direction = 'higher_worse',
        ?int $objectId = null
    ): MeasureThreshold {
        return MeasureThreshold::create([
            'organization_id' => $measure->organization_id,
            'measure_id' => $measure->id,
            'object_id' => $objectId,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'bands' => $bands,
            'direction' => $direction,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);
    }
}
