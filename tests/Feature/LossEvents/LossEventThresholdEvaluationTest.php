<?php

namespace Tests\Feature\LossEvents;

use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Services\RegulatoryThresholdService;
use App\Support\RiskCalculationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 4's acceptance criterion 2: the regulatory thresholds are evaluated
 * EXACTLY ONCE per loss-event create.
 *
 * They were evaluated twice. LossEventController::store() dispatched
 * LossEventCreated — whose EvaluateRegulatoryThresholds listener evaluates the
 * whole Nigerian threshold set — and then constructed a second
 * RegulatoryThresholdService by hand and evaluated it all again. Nothing
 * detected it because both passes produce the same answer; the cost was every
 * reported loss doing the work twice, and two places that could drift apart.
 * The listener is kept and the inline call is gone.
 */
class LossEventThresholdEvaluationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['loss_event.view', 'loss_event.create'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_title' => 'Fraudulent transfer',
            'event_description' => 'An unauthorised transfer was made from a dormant account.',
            'date_of_loss' => now()->subDays(3)->toDateString(),
            'date_discovered' => now()->subDays(1)->toDateString(),
            'business_unit_id' => $this->unit->id,
            'reported_by' => $this->actor->id,
            'basel_event_type' => 'internal_fraud',
            'event_type' => 'actual_loss',
            'severity' => 'major',
            'gross_loss_amount' => 8_000_000,
            'currency' => 'NGN',
        ], $overrides);
    }

    #[Test]
    public function the_thresholds_are_evaluated_exactly_once_per_create(): void
    {
        $calls = 0;

        // A spy on the container binding: the listener resolves the service,
        // and so did the controller. Counting resolutions of the method is the
        // only way to see a second pass, because both produce the same answer.
        $this->instance(RegulatoryThresholdService::class, new class($calls) extends RegulatoryThresholdService
        {
            public function __construct(public int &$calls) {}

            public function evaluateThresholds($lossEvent): array
            {
                $this->calls++;

                return [];
            }
        });

        $this->post(route('risk.loss-events.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, $calls, 'the regulatory thresholds are evaluated once, by the listener');
    }

    /** The listener is what does it, so the event still has to be dispatched. */
    #[Test]
    public function creating_still_raises_the_domain_event_path(): void
    {
        $this->post(route('risk.loss-events.store'), $this->payload())->assertRedirect();

        $event = LossEvent::latest('id')->firstOrFail();

        // internal_fraud above the EFCC limit writes a domain event row; the
        // listener is the only thing that writes them.
        $this->assertDatabaseHas('domain_events', [
            'source_module' => 'loss_event',
            'source_id' => $event->id,
            'event_type' => 'regulatory_threshold_breached',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The threshold is configuration now, not a literal */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_reportable_threshold_comes_from_config(): void
    {
        config(['risk.regulatory_reportable_threshold_ngn' => 1_000_000]);

        $this->post(route('risk.loss-events.store'), $this->payload(['gross_loss_amount' => 1_500_000]))
            ->assertRedirect();

        $this->assertTrue((bool) LossEvent::latest('id')->firstOrFail()->is_regulatory_reportable);

        config(['risk.regulatory_reportable_threshold_ngn' => 50_000_000]);

        $this->post(route('risk.loss-events.store'), $this->payload(['gross_loss_amount' => 1_500_000]))
            ->assertRedirect();

        $this->assertFalse((bool) LossEvent::latest('id')->firstOrFail()->is_regulatory_reportable);
    }

    #[Test]
    public function an_organisation_can_override_the_threshold(): void
    {
        $this->organization->update([
            'settings' => ['risk' => ['regulatory_reportable_threshold_ngn' => 2_000_000]],
        ]);

        // Settings are memoised per organisation for the life of the request.
        RiskCalculationSettings::flush($this->organization->id);

        $this->assertSame(
            2_000_000.0,
            RiskCalculationSettings::regulatoryReportableThresholdNgn($this->organization->id),
        );
    }

    /**
     * Criterion 2's other half: the magic number is gone from the application
     * code. Matched with word boundaries so the unrelated 1,000,000,000 and
     * 100,000,000 constants elsewhere do not count as hits.
     */
    #[Test]
    public function the_literal_threshold_is_no_longer_in_the_application_code(): void
    {
        $hits = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/(?<![\d_])10000000(?![\d_])/', file_get_contents($file->getPathname()))) {
                $hits[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits, 'the hardcoded reportability threshold is gone from app/');
    }
}
