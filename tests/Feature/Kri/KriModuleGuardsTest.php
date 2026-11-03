<?php

namespace Tests\Feature\Kri;

use App\Events\KriBreachDetected;
use App\Models\GraphObject;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\MeasureBreach;
use App\Models\Risk;
use App\Services\Kri\KriRedBandService;
use App\Services\KriMeasureBridge;
use App\Support\MorphTypes;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantFixture;

/**
 * The cross-cutting contracts of ADR 0026 that no single feature owns: the issue
 * source list, the breach-event timing, the schema rules for both database
 * engines, the migration backfill and the Issue origin panel.
 */
class KriModuleGuardsTest extends KriCycleTestCase
{
    private const MIGRATIONS = [
        '2026_11_01_100001', '2026_11_01_100002', '2026_11_01_100003', '2026_11_01_100004',
        '2026_11_01_100005', '2026_11_01_100006', '2026_11_01_100007',
    ];

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = [];
        foreach (self::MIGRATIONS as $prefix) {
            $files = array_merge($files, glob(database_path("migrations/{$prefix}_*.php")));
        }

        return $files;
    }

    /* ------------------------------------------------------------------ */
    /*  kri_breach, everywhere the source list is enumerated (8.3) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function kri_breach_is_a_full_member_of_the_issue_source_list_with_one_label_table(): void
    {
        $this->assertContains('kri_breach', Issue::SOURCES);
        $this->assertSame('KRI breach', Issue::SOURCE_LABELS['kri_breach']);
        $this->assertSame('KRI breach', Issue::sourceLabel('kri_breach'));
        $this->assertNull(Issue::sourceLabel(null));
        $this->assertSame('Weird Thing', Issue::sourceLabel('weird_thing'), 'an unknown stored value reads as itself, title-cased');

        $this->assertEqualsCanonicalizing(Issue::SOURCES, array_keys(Issue::SOURCE_LABELS), 'two lists that cannot drift apart');
    }

    #[Test]
    public function the_form_registry_derives_its_options_from_the_same_table(): void
    {
        $field = collect(\App\Support\Metadata\FormFieldRegistry::definitions()['Issue'])->firstWhere('code', 'issue_source');
        $encoded = json_encode($field['enum_options']);

        foreach (array_keys(Issue::SOURCE_LABELS) as $source) {
            $this->assertStringContainsString('"'.$source.'"', $encoded, "{$source} is offered by the form registry");
        }
    }

    #[Test]
    public function the_deployed_form_data_offers_kri_breach_too(): void
    {
        $type = DB::table('object_types')->where('code', 'Issue')->whereNull('organization_id')->first();
        $this->assertNotNull($type, 'the Issue object type is seeded');

        $attribute = DB::table('object_attributes')->where('object_type_id', $type->id)->where('code', 'issue_source')->where('is_system', true)->first();
        $this->assertNotNull($attribute);
        $this->assertStringContainsString('kri_breach', (string) $attribute->enum_options, 'the data migration replayed the registry');
    }

    #[Test]
    public function a_user_may_pick_kri_breach_by_hand_when_raising_an_issue(): void
    {
        $this->assertContains('kri_breach', Issue::SOURCES);
        $rule = (new \App\Http\Requests\Issues\StoreIssueRequest)->rules()['issue_source'] ?? [];
        $in = collect((array) $rule)->first(fn ($r) => $r instanceof \Illuminate\Validation\Rules\In);

        $this->assertNotNull($in, 'StoreIssueRequest validates the source against Issue::SOURCES');
        $this->assertStringContainsString('kri_breach', (string) $in);
    }

    /* ------------------------------------------------------------------ */
    /*  KriBreachDetected timing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_breach_event_is_deferred_by_the_service_not_by_the_event_class(): void
    {
        // The deviation from the ADR text (recorded on the event): ShouldDispatchAfterCommit would
        // move the listeners outside announceBreach()'s try/catch.
        $this->assertNotContains(ShouldDispatchAfterCommit::class, class_implements(KriBreachDetected::class));
        $this->assertStringContainsString('deferringAnnouncements', (string) file_get_contents(app_path('Services/MeasureService.php')));
    }

    #[Test]
    public function outside_a_transaction_a_red_reading_announces_at_once_as_before(): void
    {
        Event::fake([KriBreachDetected::class]);
        $kri = $this->deptKri();
        // A reading with no user writes no legacy row, and the event needs one.
        $this->actingAs($this->makeStaff('Bridge Reader'));

        app(KriMeasureBridge::class)->recordMeasurement($kri, '2026-10-15', 75.0);

        Event::assertDispatchedTimes(KriBreachDetected::class, 1);
    }

    #[Test]
    public function inside_a_deferral_scope_the_announcement_waits_for_the_commit_and_is_dropped_on_rollback(): void
    {
        Event::fake([KriBreachDetected::class]);
        $kri = $this->deptKri();
        $this->actingAs($this->makeStaff('Bridge Reader'));

        \App\Services\MeasureService::deferringAnnouncements(fn () => DB::transaction(function () use ($kri) {
            app(KriMeasureBridge::class)->recordMeasurement($kri, '2026-10-15', 75.0);
            Event::assertNotDispatched(KriBreachDetected::class);
        }));
        Event::assertDispatchedTimes(KriBreachDetected::class, 1);

        $second = $this->deptKri(null, ['name' => 'Rolled back']);
        try {
            \App\Services\MeasureService::deferringAnnouncements(fn () => DB::transaction(function () use ($second) {
                app(KriMeasureBridge::class)->recordMeasurement($second, '2026-10-15', 75.0);
                throw new \RuntimeException('roll back');
            }));
        } catch (\RuntimeException) {
        }

        Event::assertDispatchedTimes(KriBreachDetected::class, 1);   // still one: the rolled-back one never announced
        $this->assertSame(0, MeasureBreach::query()->where('object_id', app(\App\Services\MeasureService::class)->objectIdFor($second))->count());
    }

    #[Test]
    public function a_failing_listener_does_not_fail_the_reading_outside_a_transaction_either(): void
    {
        Event::listen(KriBreachDetected::class, function () {
            throw new \RuntimeException('listener down');
        });
        $kri = $this->deptKri();

        $result = app(KriMeasureBridge::class)->recordMeasurement($kri, '2026-10-15', 75.0);

        $this->assertSame('red', $result['band']);
        $this->assertSame(1, MeasureBreach::query()->where('band_to', 'red')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Schema rules (both engines) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_new_migrations_follow_the_both_engine_rules(): void
    {
        $this->assertCount(7, $this->migrationFiles());

        foreach ($this->migrationFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            $this->assertStringNotContainsString('->json(', $source, "{$name}: no json column (MySQL normalises it, MariaDB stores text)");
            $this->assertStringNotContainsString('->jsonb(', $source, $name);
            $this->assertStringNotContainsString('->enum(', $source, "{$name}: no DB ENUM");
            $this->assertStringNotContainsString('useCurrentOnUpdate', $source, $name);
            $this->assertDoesNotMatchRegularExpression('/->timestamp\(/', $source, "{$name}: *_at columns are dateTime(), written explicitly (ADR 0022)");
            $this->assertDoesNotMatchRegularExpression('/\b(ROW_NUMBER|WITH RECURSIVE|JSON_CONTAINS|JSON_EXTRACT)\b/i', $source, "{$name}: no window function, CTE or JSON function");

            preg_match_all('/->(?:unique|index)\(\s*\[[^\]]*\]\s*(?:,\s*\'([^\']+)\')?\s*\)/', $source, $matches);
            foreach ($matches[1] as $index) {
                $this->assertNotSame('', $index, "{$name}: every index has an explicit name");
                $this->assertLessThanOrEqual(64, strlen($index), "{$name}: {$index} is longer than 64 characters");
            }
            $this->assertDoesNotMatchRegularExpression('/->(?:unique|index)\(\s*\[[^\]]*\]\s*\)/', $source, "{$name}: an index without an explicit name");
        }
    }

    #[Test]
    public function every_new_tenant_table_carries_organization_id_and_a_tenant_scoped_model(): void
    {
        foreach ([
            'kri_department_contacts' => \App\Models\KriDepartmentContact::class,
            'organization_holidays' => \App\Models\OrganizationHoliday::class,
            'kri_cycle_runs' => \App\Models\KriCycleRun::class,
            'kri_returns' => KriReturn::class,
            'kri_return_lines' => \App\Models\KriReturnLine::class,
            'kri_return_notices' => \App\Models\KriReturnNotice::class,
            'kri_return_notice_recipients' => \App\Models\KriReturnNoticeRecipient::class,
        ] as $table => $model) {
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table);
            $this->assertContains(\ThirdLine\Platform\Tenancy\BelongsToOrganization::class, class_uses_recursive($model), $table);
        }
    }

    #[Test]
    public function kri_returns_has_the_optimistic_lock_column_not_null_default_zero_and_the_idempotency_key(): void
    {
        $column = collect(Schema::getColumns('kri_returns'))->firstWhere('name', 'lock_version');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
        $this->assertSame('0', (string) trim((string) $column['default'], "'"));

        $unit = $this->unit;
        $this->makeReturn($unit, [], false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeReturn($unit, [], false);   // same organisation, unit and period
    }

    #[Test]
    public function new_models_are_in_the_morph_map_and_their_aliases_fit_the_audit_column(): void
    {
        $map = MorphTypes::map();

        foreach ([
            'kri_department_contact' => \App\Models\KriDepartmentContact::class, 'organization_holiday' => \App\Models\OrganizationHoliday::class,
            'kri_cycle_run' => \App\Models\KriCycleRun::class, 'kri_return' => KriReturn::class,
            'kri_return_line' => \App\Models\KriReturnLine::class, 'kri_return_notice' => \App\Models\KriReturnNotice::class,
        ] as $alias => $class) {
            $this->assertSame($class, $map[$alias] ?? null, $alias);
            $this->assertLessThanOrEqual(\App\Models\RiskAuditTrail::ENTITY_TYPE_MAX, strlen($alias));
        }
    }

    #[Test]
    public function the_new_audit_action_names_fit_the_column(): void
    {
        foreach (['kri_contact_added', 'kri_contact_removed', 'holiday_added', 'holiday_removed', 'return_opened', 'return_submitted', 'return_reopened', 'value_changed', 'line_discarded'] as $action) {
            $this->assertLessThanOrEqual(20, strlen($action), "ADR 0026 section 13 caps action_type at 20: {$action}");
        }
    }

    #[Test]
    public function opening_a_return_is_audited(): void
    {
        $this->deptKri();
        \Illuminate\Support\Facades\Mail::fake();

        $this->artisan('kri:open-cycle')->assertExitCode(0);

        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_type' => 'kri_return', 'entity_id' => $this->theReturn()->id, 'action_type' => 'return_opened', 'new_value' => 'draft',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The business_unit_id backfill (ADR 0026 section 1) */
    /* ------------------------------------------------------------------ */

    private function backfill(): void
    {
        $file = glob(database_path('migrations/2026_11_01_100001_*.php'))[0];
        $migration = require $file;
        $method = new \ReflectionMethod($migration, 'backfill');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    private function bareKri(?int $riskId = null, array $extra = []): KeyRiskIndicator
    {
        $kri = $this->makeKri(array_merge(['risk_id' => $riskId ?? $this->makeRisk()->id, 'business_unit_id' => null], $extra));
        DB::table('key_risk_indicators')->where('id', $kri->id)->update(['business_unit_id' => null]);

        return $kri->fresh();
    }

    private function unitOf(KeyRiskIndicator $kri): ?int
    {
        $value = DB::table('key_risk_indicators')->where('id', $kri->id)->value('business_unit_id');

        return $value === null ? null : (int) $value;
    }

    #[Test]
    public function the_backfill_resolves_a_department_by_the_documented_rules_in_order(): void
    {
        $nodeUnit = $this->makeUnit('Node Unit', 'N-U');
        $riskUnit = $this->makeUnit('Risk Unit', 'R-U');
        $mapUnit = $this->makeUnit('Map Unit', 'M-U');
        $otherMapUnit = $this->makeUnit('Other Map Unit', 'O-M');

        // Rule 1: the KRI's node is a business_unit object.
        $byNode = $this->bareKri();
        $objectId = GraphObject::query()->where('source_model_type', 'business_unit')->where('source_model_id', $nodeUnit->id)->value('id');
        $this->assertNotNull($objectId, 'a business unit has a graph object');
        DB::table('key_risk_indicators')->where('id', $byNode->id)->update(['node_id' => $objectId]);

        // Rule 1 beats rule 2.
        $both = $this->bareKri($this->makeRisk(['business_unit_id' => $riskUnit->id])->id);
        DB::table('key_risk_indicators')->where('id', $both->id)->update(['node_id' => $objectId]);

        // Rule 2: the KRI's own risk.
        $byRisk = $this->bareKri($this->makeRisk(['business_unit_id' => $riskUnit->id])->id);

        // Rule 3: every mapped risk shares one unit.
        $byMapping = $this->bareKri();
        // Rule 3 voided: the mapped risks disagree.
        $disagree = $this->bareKri();
        foreach ([[$byMapping, $mapUnit], [$byMapping, $mapUnit], [$disagree, $mapUnit], [$disagree, $otherMapUnit]] as [$kri, $unit]) {
            (new TenantFixture)->make('risk_kri_mapping', $this->organization->id, [
                'kri_id' => $kri->id, 'risk_id' => $this->makeRisk(['business_unit_id' => $unit->id])->id,
            ]);
        }

        // No link at all.
        $nothing = $this->bareKri();

        // A unit that is soft-deleted, and one in another tenant, are not valid answers.
        $deleted = $this->makeUnit('Gone Unit', 'GONE');
        $viaDeleted = $this->bareKri($this->makeRisk(['business_unit_id' => $deleted->id])->id);
        $deleted->delete();
        $viaForeign = $this->bareKri($this->makeRisk(['business_unit_id' => $this->foreignDepartment()->id])->id);

        $this->backfill();

        $this->assertSame($nodeUnit->id, $this->unitOf($byNode));
        $this->assertSame($nodeUnit->id, $this->unitOf($both), 'node first');
        $this->assertSame($riskUnit->id, $this->unitOf($byRisk));
        $this->assertSame($mapUnit->id, $this->unitOf($byMapping));
        $this->assertNull($this->unitOf($disagree), 'it does not guess between disagreeing links');
        $this->assertNull($this->unitOf($nothing), 'unassigned');
        $this->assertNull($this->unitOf($viaDeleted));
        $this->assertNull($this->unitOf($viaForeign), "another tenant's unit is never assigned");
    }

    /* ------------------------------------------------------------------ */
    /*  The Issue's "Raised from KRI breach" origin (8.3) */
    /* ------------------------------------------------------------------ */

    private function raisedIssue(): Issue
    {
        $this->actingAs($this->actor)->postJson(
            route('risk.kri.record-measurement', $this->kriForOrigin),
            ['measurement_date' => '2026-10-15', 'value' => 75, 'red_band' => $this->explanation()]
        )->assertRedirect();

        return Issue::query()->where('issue_source', 'kri_breach')->firstOrFail();
    }

    private KeyRiskIndicator $kriForOrigin;

    #[Test]
    public function the_origin_of_a_reading_raised_issue_names_the_kri_the_breach_and_the_root_cause(): void
    {
        $this->kriForOrigin = $this->deptKri(null, ['name' => 'Settlement fails', 'unit_of_measure' => '%']);
        $issue = $this->raisedIssue();
        $breach = MeasureBreach::query()->where('linked_issue_id', $issue->id)->firstOrFail();

        $origin = app(KriRedBandService::class)->originFor($issue);

        $this->assertSame($this->kriForOrigin->id, $origin['kri']['id']);
        $this->assertSame($this->kriForOrigin->kri_code, $origin['kri']['code']);
        $this->assertSame('%', $origin['kri']['unit']);
        $this->assertSame('higher_worse', $origin['kri']['direction']);
        $this->assertSame($breach->id, $origin['breach']['id']);
        $this->assertEquals(75.0, $origin['breach']['value']);
        $this->assertEquals(50.0, $origin['breach']['red_threshold']);
        $this->assertSame($this->explanation()['root_cause'], $origin['breach']['root_cause']);
        $this->assertSame($this->explanation()['root_cause'], $origin['root_cause']);
        $this->assertNull($origin['return'], 'raised from the Record form, not from a return');
    }

    #[Test]
    public function with_no_breach_the_root_cause_falls_back_to_the_issues_observation(): void
    {
        $this->kriForOrigin = $this->deptKri();

        $this->actingAs($this->actor)->putJson(route('risk.kri.update', $this->kriForOrigin), [
            'kri_name' => 'Edited', 'measurement_unit' => '%', 'measurement_frequency' => 'monthly', 'kri_owner_id' => $this->actor->id,
            'direction' => 'higher_is_worse', 'green_threshold' => 10, 'red_threshold' => 50, 'target_value' => 60,
            'business_unit_id' => $this->unit->id, 'red_band' => $this->explanation(),
        ])->assertRedirect();
        $issue = Issue::query()->where('issue_source', 'kri_breach')->firstOrFail();

        $origin = app(KriRedBandService::class)->originFor($issue);

        $this->assertNull($origin['breach']);
        $this->assertSame($this->explanation()['root_cause'], $origin['root_cause']);
        $this->assertSame($this->kriForOrigin->id, $origin['kri']['id']);
    }

    #[Test]
    public function an_issue_with_no_edge_to_a_kri_has_no_origin_and_the_page_carries_it_when_it_has(): void
    {
        $plain = Issue::create([
            'organization_id' => $this->organization->id, 'issue_reference' => 'ISS-PLAIN', 'title' => 'Plain', 'description' => 'x',
            'issue_source' => 'kri_breach', 'issue_category' => 'KRI breach', 'priority' => 'high', 'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id, 'created_by' => $this->actor->id,
        ]);
        $this->assertNull(app(KriRedBandService::class)->originFor($plain), 'a hand-picked kri_breach source is only a label');

        $this->kriForOrigin = $this->deptKri();
        $issue = $this->raisedIssue();
        $viewer = $this->makeStaff('Issue Viewer', ['issue.view']);

        $props = $this->actingAs($viewer)->get(route('risk.issues.show', $issue))->assertOk()->viewData('page')['props'];
        $found = [];
        array_walk_recursive($props, function ($v, $k) use (&$found) {
            if ($k === 'code') {
                $found[] = $v;
            }
        });

        $this->assertContains($this->kriForOrigin->kri_code, $found, 'the Issue page carries the KRI origin');
        $labels = [];
        array_walk_recursive($props, function ($v, $k) use (&$labels) {
            if ($k === 'sourceLabel') {
                $labels[] = $v;
            }
        });
        $this->assertSame(['KRI breach'], $labels, 'sourceLabel is sent, not a title-cased source');
    }

    #[Test]
    public function the_demo_tenant_seed_carries_the_departmental_cycle(): void
    {
        // Gate line: seed/demo data is added to the Kano Heritage Bank demo tenant. A source
        // check rather than a full seed run, which is slow and owned by DemoDataSeeder's own tests.
        $seeders = '';
        foreach (glob(database_path('seeders/*.php')) as $file) {
            $seeders .= (string) file_get_contents($file);
        }

        $this->assertStringContainsString('KriDepartmentContact', $seeders, 'no demo department contacts are seeded');
        $this->assertStringContainsString('business_unit_id', $seeders);
        $this->assertMatchesRegularExpression('/OrganizationHoliday|organization_holidays/', $seeders, 'no demo holiday list is seeded');
        $this->assertStringNotContainsString('mohalidesigns', $seeders, 'the live-test address is never committed');
    }
}
