<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Support\Tprm\FactRegistry;
use App\Support\Tprm\RuleEvaluator;
use Database\Seeders\Tprm\Reference\ClauseLibrary;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The seeded reference libraries, and the honesty of their own completeness
 * claims.
 *
 * The phase's acceptance criterion is that every seeded framework returns the
 * expected control count. That is asserted here against each framework's OWN
 * declared count rather than against a list restated in the test, so a
 * framework that grows a control and forgets to say so fails, and a framework
 * that admits to being partial is not held to a standard it never claimed.
 */
class ReferenceDataSeedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Seed Test Bank',
            'short_name' => 'STB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        // The operational risk area the third-party area is hung beneath. The
        // seeder degrades gracefully without it — asserted separately below.
        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR',
            'name' => 'Operational Risk',
            'level' => 1,
            'is_active' => true,
        ]);

        $this->seed(TprmReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Framework libraries */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_framework_marked_complete_holds_the_number_of_controls_it_declares(): void
    {
        $frameworks = DB::table('tp_frameworks')->where('catalogue_status', 'complete')->get();

        $this->assertGreaterThanOrEqual(9, $frameworks->count());

        foreach ($frameworks as $framework) {
            $actual = DB::table('tp_framework_controls')->where('framework_id', $framework->id)->count();

            $this->assertSame(
                (int) $framework->declared_control_count,
                $actual,
                "Framework {$framework->code} claims to be a complete catalogue of "
                ."{$framework->declared_control_count} controls but holds {$actual}."
            );
        }
    }

    #[Test]
    public function every_partial_framework_says_why_and_holds_fewer_than_it_declares(): void
    {
        // A catalogue marked partial has to explain itself. The note is what a
        // screen shows a user so they do not believe "no mapping found" means
        // "no such control".
        $partial = DB::table('tp_frameworks')->where('catalogue_status', 'partial')->get();

        $this->assertNotEmpty($partial, 'No framework is marked partial, which would mean every licensed standard was reproduced in full.');

        foreach ($partial as $framework) {
            $this->assertNotNull($framework->catalogue_note, "Framework {$framework->code} is partial but says nothing about why.");

            $actual = DB::table('tp_framework_controls')->where('framework_id', $framework->id)->count();
            $this->assertGreaterThan(0, $actual, "Framework {$framework->code} is seeded with no controls at all.");

            if ($framework->declared_control_count !== null) {
                $this->assertLessThan(
                    (int) $framework->declared_control_count,
                    $actual,
                    "Framework {$framework->code} is marked partial but holds everything it declares — mark it complete."
                );
            }
        }
    }

    #[Test]
    public function iso_27002_carries_all_93_controls_with_the_five_supplier_ones_flagged(): void
    {
        $frameworkId = DB::table('tp_frameworks')->where('code', 'iso27002')->value('id');

        $this->assertSame(93, DB::table('tp_framework_controls')->where('framework_id', $frameworkId)->count());

        $supplierControls = DB::table('tp_framework_controls')
            ->where('framework_id', $frameworkId)
            ->where('supplier_relevant', true)
            ->pluck('control_id')
            ->sort()
            ->values()
            ->all();

        // A.5.19–A.5.23 and nothing else. A builder that flags forty controls
        // as supplier-relevant has flagged none of them.
        $this->assertSame(['5.19', '5.20', '5.21', '5.22', '5.23'], $supplierControls);
    }

    #[Test]
    public function unstable_framework_keys_are_flagged_as_such(): void
    {
        // TRD §4.3: SIG domain letters and DORA template codes are not stable
        // between releases, so anything mapped against them must be re-verified
        // on a version change. The flag is how a screen knows to say so.
        $this->assertFalse(
            (bool) DB::table('tp_frameworks')->where('code', 'vrmmm')->value('has_stable_keys')
        );

        foreach (['iso27002', 'csf20', 'pci_dss_401', 'nist80053r5', 'ccm_v4'] as $code) {
            $this->assertTrue(
                (bool) DB::table('tp_frameworks')->where('code', $code)->value('has_stable_keys'),
                "Framework {$code} should have stable join keys."
            );
        }
    }

    #[Test]
    public function framework_libraries_are_system_owned_and_re_seed_without_duplicating(): void
    {
        $this->assertSame(
            0,
            DB::table('tp_frameworks')->whereNotNull('organization_id')->count(),
            'A shipped framework library must belong to no tenant.'
        );

        $before = DB::table('tp_framework_controls')->count();
        $this->seed(TprmReferenceSeeder::class);

        $this->assertSame($before, DB::table('tp_framework_controls')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Clause library */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_clause_applicability_rule_is_evaluable_and_uses_whitelisted_facts(): void
    {
        // A clause whose rule references a fact the registry does not know
        // would never fire, and a blocking clause that never fires is a gate
        // that silently lets everything through.
        $evaluator = new RuleEvaluator;

        foreach (ClauseLibrary::clauses() as $clause) {
            $rule = $clause['applicability_rule'];

            foreach (FactRegistry::factsUsedBy($rule) as $fact) {
                $this->assertTrue(
                    FactRegistry::allows($fact),
                    "Clause {$clause['code']} references `{$fact}`, which the fact registry does not admit."
                );
            }

            // And it evaluates without throwing against an empty context.
            $this->assertIsBool($evaluator->evaluate($rule, []));
        }
    }

    #[Test]
    public function the_audit_rights_clause_is_blocking_carries_its_citation_and_cannot_be_deleted_by_a_tenant(): void
    {
        // AC-06 is written around this clause specifically.
        $clause = DB::table('tp_clause_library')->where('code', 'CBN-CYB-05')->first();

        $this->assertNotNull($clause);
        $this->assertTrue((bool) $clause->is_blocking);
        $this->assertSame('CBN Cyber 2024 §2.3(v)', $clause->citation);
        $this->assertTrue((bool) $clause->is_system_owned);
        $this->assertNull($clause->organization_id);
    }

    #[Test]
    public function a_pci_clause_applies_only_to_a_pci_engagement(): void
    {
        $evaluator = new RuleEvaluator;
        $rule = collect(ClauseLibrary::clauses())->firstWhere('code', 'PCI-12.8.5')['applicability_rule'];

        $this->assertTrue($evaluator->evaluate($rule, ['engagement.pci_in_scope' => true]));

        // A stationery contract must not show a PCI gap, or the gap report
        // stops being read.
        $this->assertFalse($evaluator->evaluate($rule, ['engagement.pci_in_scope' => false]));
    }

    /* ------------------------------------------------------------------ */
    /*  Tenant reference data and the ERM bridge */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_three_prohibited_functions_are_flagged_and_cite_their_source(): void
    {
        // AC-01 is enforced against these rows.
        $prohibited = DB::table('tp_business_functions')
            ->where('organization_id', $this->organization->id)
            ->where('is_prohibited_outsourcing', true)
            ->get();

        $this->assertSame(
            ['BF-AUD-01', 'BF-COMP-01', 'BF-CSEC-01'],
            $prohibited->pluck('function_code')->sort()->values()->all()
        );

        foreach ($prohibited as $function) {
            $this->assertSame(
                'CBN Corporate Governance Guidelines 2023 §13.1, §3.6.2',
                $function->prohibition_citation,
                'A prohibited function with no citation would show a user a block with no reason.'
            );
        }
    }

    #[Test]
    public function a_third_party_risk_area_is_added_to_the_erm_taxonomy_under_operational_risk(): void
    {
        $area = RiskCategory::query()
            ->where('organization_id', $this->organization->id)
            ->where('code', 'OR-TP')
            ->first();

        $this->assertNotNull($area, 'Third-party exposure has no key risk area to roll up into.');
        $this->assertSame('Third-Party and Outsourcing Risk', $area->name);
        $this->assertSame('Operational Risk', $area->basel_category);

        $operational = RiskCategory::query()
            ->where('organization_id', $this->organization->id)
            ->where('code', 'OR')
            ->first();

        $this->assertSame($operational->id, $area->parent_id);
    }

    #[Test]
    public function every_vendor_category_points_at_a_key_risk_area(): void
    {
        // This mapping is what makes "how much of our operational risk area is
        // third-party exposure" a query instead of a spreadsheet.
        $unmapped = DB::table('tp_categories')
            ->where('organization_id', $this->organization->id)
            ->whereNull('erm_risk_category_id')
            ->pluck('code')
            ->all();

        $this->assertSame([], $unmapped, 'Vendor categories with no ERM risk area: '.implode(', ', $unmapped));
    }

    #[Test]
    public function the_seeder_degrades_rather_than_failing_when_a_tenant_has_no_taxonomy(): void
    {
        // A tenant that has not seeded a risk taxonomy still gets a working
        // module; its engagements simply report as uncategorised in the
        // roll-up, which is a true statement rather than a guess.
        $bare = Organization::create([
            'name' => 'No Taxonomy Bank',
            'short_name' => 'NTB',
            'institution_type' => 'microfinance_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        (new TprmReferenceSeeder)->seedForOrganization($bare);

        $this->assertSame(20, DB::table('tp_categories')->where('organization_id', $bare->id)->count());
        $this->assertSame(
            20,
            DB::table('tp_categories')->where('organization_id', $bare->id)->whereNull('erm_risk_category_id')->count()
        );
        $this->assertNull(RiskCategory::query()->where('organization_id', $bare->id)->where('code', 'OR-TP')->first());
    }

    #[Test]
    public function all_four_tier_policies_exist_and_the_top_two_are_board_reportable(): void
    {
        $policies = DB::table('tp_tier_policies')
            ->where('organization_id', $this->organization->id)
            ->get()
            ->keyBy('tier');

        $this->assertCount(4, $policies);

        $this->assertTrue((bool) $policies['critical']->board_reportable);
        $this->assertTrue((bool) $policies['high']->board_reportable);
        $this->assertFalse((bool) $policies['moderate']->board_reportable);
        $this->assertFalse((bool) $policies['low']->board_reportable);

        // Exit planning is mandatory at the top two tiers (§6.13).
        $this->assertTrue((bool) $policies['critical']->exit_plan_required);
        $this->assertTrue((bool) $policies['high']->exit_plan_required);
        $this->assertFalse((bool) $policies['low']->exit_plan_required);

        // And a Critical vendor is reassessed and rescreened more often than a
        // Low one — the whole point of tiering.
        $this->assertLessThan(
            (int) $policies['low']->assessment_frequency_months,
            (int) $policies['critical']->assessment_frequency_months
        );
        $this->assertLessThan(
            (int) $policies['low']->screening_frequency_months,
            (int) $policies['critical']->screening_frequency_months
        );
    }

    #[Test]
    public function document_types_declare_the_assurance_they_can_support(): void
    {
        $soc2 = DB::table('tp_document_types')->where('code', 'soc2_type2')->first();
        $bridge = DB::table('tp_document_types')->where('code', 'bridge_letter')->first();

        $this->assertSame('independently_assured', $soc2->default_assurance_level);
        $this->assertSame('soc2', $soc2->extractor);

        // AC-05: a bridge letter is the vendor's assertion that nothing
        // changed, not an auditor's opinion that nothing did.
        $this->assertSame('documented', $bridge->default_assurance_level);

        // A contract proves an undertaking, not an operating control.
        $this->assertFalse(
            (bool) DB::table('tp_document_types')->where('code', 'contract')->value('is_assurance_evidence')
        );
    }
}
