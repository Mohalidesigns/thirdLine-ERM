<?php

namespace Tests\Feature\Characterisation;

use App\Services\RegulatoryThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * CHARACTERISATION — crown jewel.
 *
 * Pins every threshold, deadline and statutory citation in
 * RegulatoryThresholdService exactly as they behave today. These assertions
 * are not a specification anyone is free to "improve": the numbers are drawn
 * from CBN, NFIU, NDIC and EFCC instruments, and a silent change to one of
 * them is a compliance failure that surfaces months later in an examination.
 *
 * If a test here fails, the correct first assumption is that the production
 * change is wrong — not the test.
 */
class RegulatoryThresholdServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private RegulatoryThresholdService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Deadlines are computed from now(); freeze so "+7 days" is assertable.
        Carbon::setTestNow('2026-06-15 09:00:00');

        $this->bootDomainFixtures();
        $this->service = new RegulatoryThresholdService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Threshold constants */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function threshold_constants_are_the_published_naira_amounts(): void
    {
        // Stored in kobo. The naira figure each one encodes is spelled out so
        // a future edit has to confront the actual regulatory amount.
        $this->assertSame(500_000_000, RegulatoryThresholdService::CBN_THRESHOLD_KOBO, 'CBN: NGN 5,000,000');
        $this->assertSame(500_000_000, RegulatoryThresholdService::NFIU_STR_THRESHOLD_KOBO, 'NFIU STR: NGN 5,000,000');
        $this->assertSame(1_000_000_000, RegulatoryThresholdService::NFIU_CTR_THRESHOLD_KOBO, 'NFIU CTR: NGN 10,000,000');
        $this->assertSame(50_000_000, RegulatoryThresholdService::NDIC_THRESHOLD_KOBO, 'NDIC: NGN 500,000');
        $this->assertSame(100_000_000, RegulatoryThresholdService::EFCC_THRESHOLD_KOBO, 'EFCC: NGN 1,000,000');
    }

    /* ------------------------------------------------------------------ */
    /*  CBN — BSD/DIR/GEN/LAB/07/014, 7 days */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function cbn_alert_fires_at_exactly_the_threshold_and_sets_a_seven_day_deadline(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => RegulatoryThresholdService::CBN_THRESHOLD_KOBO,
        ]);

        $alerts = $this->service->evaluateThresholds($event);
        $cbn = $this->alertOfType($alerts, 'CBN_REPORTING_REQUIRED');

        $this->assertNotNull($cbn, 'CBN alert must fire at exactly NGN 5,000,000');
        $this->assertSame('CBN BSD/DIR/GEN/LAB/07/014', $cbn['regulatory_ref']);
        $this->assertStringContainsString('within 7 days', $cbn['message']);
        $this->assertSame(
            Carbon::parse('2026-06-22 09:00:00')->toIso8601String(),
            $cbn['deadline'],
            'CBN notification is due 7 days after evaluation'
        );

        $event->refresh();
        $this->assertTrue((bool) $event->cbn_reportable);
        $this->assertSame('2026-06-22', $event->cbn_reporting_deadline->toDateString());
    }

    #[Test]
    public function cbn_alert_does_not_fire_one_kobo_below_the_threshold(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => RegulatoryThresholdService::CBN_THRESHOLD_KOBO - 1,
        ]);

        $alerts = $this->service->evaluateThresholds($event);

        $this->assertNull($this->alertOfType($alerts, 'CBN_REPORTING_REQUIRED'));
        $this->assertFalse((bool) $event->fresh()->cbn_reportable);
    }

    /* ------------------------------------------------------------------ */
    /*  NFIU — AML/CFT Act 2022 §6(1), 24 hours */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('fraudCategories')]
    public function nfiu_str_alert_fires_for_every_fraud_category(string $baselCategory): void
    {
        $event = $this->makeLossEvent(['basel_l1_category' => $baselCategory]);

        $alerts = $this->service->evaluateThresholds($event);
        $nfiu = $this->alertOfType($alerts, 'NFIU_STR_REQUIRED');

        $this->assertNotNull($nfiu, "NFIU STR must fire for {$baselCategory}");
        $this->assertSame('AML/CFT Act 2022 §6(1); NFIU Regulations 2024 §4.2', $nfiu['regulatory_ref']);
        $this->assertStringContainsString('within 24 hours', $nfiu['message']);
        $this->assertSame(
            Carbon::parse('2026-06-16 09:00:00')->toIso8601String(),
            $nfiu['deadline'],
            'STR is due 24 hours after evaluation'
        );

        $this->assertTrue((bool) $event->fresh()->nfiu_reportable);
    }

    public static function fraudCategories(): array
    {
        return [
            'internal fraud' => ['INTERNAL_FRAUD'],
            'external fraud' => ['EXTERNAL_FRAUD'],
        ];
    }

    #[Test]
    public function nfiu_str_alert_fires_on_report_type_even_for_a_non_fraud_category(): void
    {
        // The report type is an independent trigger: a compliance officer who
        // has already classified the event as an STR does not lose the alert
        // because the Basel category is something other than fraud.
        $event = $this->makeLossEvent([
            'basel_l1_category' => 'EXECUTION_DELIVERY',
            'nfiu_report_type' => 'STR',
        ]);

        $alerts = $this->service->evaluateThresholds($event);

        $this->assertNotNull($this->alertOfType($alerts, 'NFIU_STR_REQUIRED'));
        $this->assertTrue((bool) $event->fresh()->nfiu_reportable);
    }

    #[Test]
    public function nfiu_str_alert_is_independent_of_the_loss_amount(): void
    {
        // Zero-value fraud still triggers the STR. Suspicion, not size, is the
        // statutory test.
        $event = $this->makeLossEvent([
            'basel_l1_category' => 'INTERNAL_FRAUD',
            'gross_loss_amount_kobo' => 0,
        ]);

        $this->assertNotNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'NFIU_STR_REQUIRED')
        );
    }

    #[Test]
    public function nfiu_str_alert_does_not_fire_for_a_non_fraud_event(): void
    {
        $event = $this->makeLossEvent(['basel_l1_category' => 'EXECUTION_DELIVERY']);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'NFIU_STR_REQUIRED')
        );
        $this->assertFalse((bool) $event->fresh()->nfiu_reportable);
    }

    /* ------------------------------------------------------------------ */
    /*  NDIC — NDIC Act 2023 §41 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ndic_alert_fires_at_exactly_the_threshold_and_carries_no_deadline(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => RegulatoryThresholdService::NDIC_THRESHOLD_KOBO,
        ]);

        $alerts = $this->service->evaluateThresholds($event);
        $ndic = $this->alertOfType($alerts, 'NDIC_REPORTING_REQUIRED');

        $this->assertNotNull($ndic);
        $this->assertSame('NDIC Act 2023 §41', $ndic['regulatory_ref']);
        $this->assertArrayNotHasKey('deadline', $ndic, 'NDIC notification has no statutory clock in this engine');
        $this->assertTrue((bool) $event->fresh()->ndic_reportable);
    }

    #[Test]
    public function ndic_alert_does_not_fire_one_kobo_below_the_threshold(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => RegulatoryThresholdService::NDIC_THRESHOLD_KOBO - 1,
        ]);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'NDIC_REPORTING_REQUIRED')
        );
        $this->assertFalse((bool) $event->fresh()->ndic_reportable);
    }

    /* ------------------------------------------------------------------ */
    /*  EFCC — EFCC Act 2004 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function efcc_alert_requires_both_a_fraud_category_and_the_amount(): void
    {
        $event = $this->makeLossEvent([
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'gross_loss_amount_kobo' => RegulatoryThresholdService::EFCC_THRESHOLD_KOBO,
        ]);

        $efcc = $this->alertOfType($this->service->evaluateThresholds($event), 'EFCC_REPORTING_RECOMMENDED');

        $this->assertNotNull($efcc);
        $this->assertSame('EFCC Act 2004 (as amended)', $efcc['regulatory_ref']);
    }

    #[Test]
    public function efcc_alert_does_not_fire_for_a_large_non_fraud_loss(): void
    {
        $event = $this->makeLossEvent([
            'basel_l1_category' => 'DAMAGE_PHYSICAL_ASSETS',
            'gross_loss_amount_kobo' => 900_000_000,
        ]);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'EFCC_REPORTING_RECOMMENDED')
        );
    }

    #[Test]
    public function efcc_alert_does_not_fire_for_a_small_fraud_loss(): void
    {
        $event = $this->makeLossEvent([
            'basel_l1_category' => 'INTERNAL_FRAUD',
            'gross_loss_amount_kobo' => RegulatoryThresholdService::EFCC_THRESHOLD_KOBO - 1,
        ]);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'EFCC_REPORTING_RECOMMENDED')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Law enforcement — CBN Cyber Security Framework 2021, 24 hours */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function cyber_enabled_fraud_triggers_a_twenty_four_hour_law_enforcement_notification(): void
    {
        $event = $this->makeLossEvent([
            'cbn_risk_category' => 'TECHNOLOGY_RISK',
            'basel_l1_category' => 'EXTERNAL_FRAUD',
        ]);

        $alert = $this->alertOfType($this->service->evaluateThresholds($event), 'LAW_ENFORCEMENT_NOTIFICATION');

        $this->assertNotNull($alert);
        $this->assertSame('CBN Cyber Security Framework 2021', $alert['regulatory_ref']);
        $this->assertSame(Carbon::parse('2026-06-16 09:00:00')->toIso8601String(), $alert['deadline']);
    }

    #[Test]
    public function technology_risk_without_fraud_does_not_notify_law_enforcement(): void
    {
        $event = $this->makeLossEvent([
            'cbn_risk_category' => 'TECHNOLOGY_RISK',
            'basel_l1_category' => 'BUSINESS_DISRUPTION',
        ]);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'LAW_ENFORCEMENT_NOTIFICATION')
        );
    }

    #[Test]
    public function fraud_outside_technology_risk_does_not_notify_law_enforcement(): void
    {
        $event = $this->makeLossEvent([
            'cbn_risk_category' => 'PROCESS_RISK',
            'basel_l1_category' => 'INTERNAL_FRAUD',
        ]);

        $this->assertNull(
            $this->alertOfType($this->service->evaluateThresholds($event), 'LAW_ENFORCEMENT_NOTIFICATION')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Composition */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_large_cyber_fraud_raises_every_alert_in_declaration_order(): void
    {
        $event = $this->makeLossEvent([
            'cbn_risk_category' => 'TECHNOLOGY_RISK',
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'gross_loss_amount_kobo' => 900_000_000,
        ]);

        $types = array_column($this->service->evaluateThresholds($event), 'type');

        $this->assertSame([
            'CBN_REPORTING_REQUIRED',
            'NFIU_STR_REQUIRED',
            'NDIC_REPORTING_REQUIRED',
            'EFCC_REPORTING_RECOMMENDED',
            'LAW_ENFORCEMENT_NOTIFICATION',
        ], $types);
    }

    #[Test]
    public function an_unremarkable_event_raises_nothing_and_flips_no_flags(): void
    {
        $event = $this->makeLossEvent(['gross_loss_amount_kobo' => 1_000]);

        $this->assertSame([], $this->service->evaluateThresholds($event));

        $event->refresh();
        $this->assertFalse((bool) $event->cbn_reportable);
        $this->assertFalse((bool) $event->nfiu_reportable);
        $this->assertFalse((bool) $event->ndic_reportable);
        $this->assertNull($event->cbn_reporting_deadline);
    }

    /* ------------------------------------------------------------------ */

    private function alertOfType(array $alerts, string $type): ?array
    {
        foreach ($alerts as $alert) {
            if ($alert['type'] === $type) {
                return $alert;
            }
        }

        return null;
    }
}
