<?php

namespace Tests\Feature\Sm;

use App\Models\Organization;
use App\Models\RiskTaxonomy;
use App\Services\Sm\OpEventTypeCatalogue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The OP1–OP17 seeding (§A1.9, gate checklist §A).
 *
 * EXTENDS `Tests\TestCase`, NOT `SmTestCase`: the shared fixture seeds the OP tree in setUp, and a
 * suite about the seeder cannot start from a database the seeder has already populated.
 *
 * THE THREE PROPERTIES THAT MATTER:
 *
 *   1. NAMES VERBATIM. D1 makes the workbook the specification, and the labels are what a
 *      customer's existing loss-data templates and signed-off scenarios already say. A
 *      "neutralised" rewrite would be an unreviewed editorial change to a regulatory vocabulary
 *      made by whoever wrote the seeder — so the assertions compare against the extraction, not
 *      against a copy of the seeder's own list.
 *   2. IDEMPOTENT, AND WITHOUT RESURRECTING ANYTHING. `firstOrCreate` on the natural key, then
 *      repair only `parent_id`/`depth`/`sort_order` where they are null/0 — never `is_active`,
 *      because re-activating a node a tenant retired is the one line that could undo their edit.
 *   3. PER TENANT. `risk_taxonomies.organization_id` is NOT NULL, so BCMS's system-row mechanism
 *      is unavailable and each tenant gets its own copy. That is not duplication for its own sake:
 *      `name` is tenant-editable, so a bank can rename OP13 and keep the OP number.
 */
class OpEventTypeCatalogueTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Organization $org;

    private OpEventTypeCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogue = app(OpEventTypeCatalogue::class);

        $this->org = Organization::create([
            'name' => 'Test Bank PLC',
            'short_name' => 'TSTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_creates_one_parent_and_seventeen_children_under_basel_iii(): void
    {
        // ONE PARENT with 17 children rather than 17 loose roots: the admin screen is a tree
        // (`RiskTaxonomy::children()` orders by sort_order), 17 roots beside a tenant's own nodes
        // is unusable, and a parent gives the scenario form one indexed query on the existing
        // `index(organization_id, parent_id)`.
        $result = $this->catalogue->ensureFor($this->org);

        $this->assertSame(['created' => 17, 'repaired' => 0, 'total' => 17], $result);

        $parent = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', OpEventTypeCatalogue::PARENT_NAME)
            ->sole();

        $this->assertSame(OpEventTypeCatalogue::FRAMEWORK, $parent->framework);
        $this->assertNull($parent->parent_id);
        $this->assertSame(0, (int) $parent->depth);
        $this->assertSame(0, (int) $parent->sort_order);

        $children = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('parent_id', $parent->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(17, $children);
        $this->assertSame(range(1, 17), $children->pluck('sort_order')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue($children->every(fn (RiskTaxonomy $node) => (int) $node->depth === 1));
        $this->assertTrue($children->every(fn (RiskTaxonomy $node) => $node->framework === 'Basel III'));
        $this->assertTrue($children->every(fn (RiskTaxonomy $node) => (bool) $node->is_active));
    }

    #[Test]
    public function the_seventeen_names_are_the_workbooks_verbatim(): void
    {
        // From plans/scenario-modelling/VOCABULARY-EXTRACTION.md, LS sheet [25,13]–[41,13], with
        // the `OP{n} ` prefix §A1.9 freezes. Spelled out here rather than read from the const, so
        // a change to the const is a change a reviewer has to look at.
        $this->catalogue->ensureFor($this->org);

        $expected = [
            'OP1 Internal Fraud & intentional unauthorised activity',
            'OP2 External Fraud & system security fraud',
            'OP3 Employee relations, diversity & discrimination',
            'OP4 Safe environment',
            'OP5 Loss of key staff/talent management',
            'OP6 Suitability, disclosure & breach of fiduciary duty',
            'OP7 Improper business or market practices leading to breach of laws & regulations (Antitrust, unlicensed activity, money laundering, breach of insurance laws)',
            'OP8 Product flaws',
            'OP9 Disasters & other events',
            'OP10 Breach of information security',
            'OP11 System disruption (unavailability, under-investment in IT or telecommunication)',
            'OP12 Transaction, capture, execution & maintenance',
            'OP13 Failures in u/w or claims (non intentional) processes',
            'OP14 Public reporting & disclosure risk (including Financial reporting, rating issues & IR)',
            'OP15 Project management failure',
            'OP16 Internal vendors misperformance or failure, incl. distribution misperformance (tied agents)',
            'OP17 External vendors misperformance or failure, incl. distribution misperformance (brokers)',
        ];

        $actual = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->whereNotNull('parent_id')
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        $this->assertSame($expected, $actual);

        // The insurer-flavoured wording is KEPT, not bank-neutralised: "u/w", "tied agents" and
        // "brokers" are all still there. §A1.9's reasoning — D1 makes the workbook the
        // specification, and a bank that wants different words edits its own tenant-editable node.
        $this->assertStringContainsString('u/w', $actual[12]);
        $this->assertStringContainsString('tied agents', $actual[15]);
        $this->assertStringContainsString('brokers', $actual[16]);
    }

    #[Test]
    public function every_name_fits_the_column_it_is_written_into(): void
    {
        // ADR 0014's whole lesson, and §A1.9 measured it rather than assuming: `name` is
        // string(255) and the longest of the 17 is OP7 at 158 characters, leaving room for a
        // tenant's CBN ORMS extension. SQLite never enforces a declared width, so an over-long
        // name passes every test and throws SQLSTATE[22001] on MySQL.
        $this->catalogue->ensureFor($this->org);

        $longest = 0;

        foreach (OpEventTypeCatalogue::OP_EVENT_TYPES as [$name, $baselLevel1]) {
            $this->assertLessThanOrEqual(255, mb_strlen($name), $name);
            $this->assertLessThanOrEqual(255, mb_strlen(OpEventTypeCatalogue::BASEL_DESCRIPTION_PREFIX.$baselLevel1));
            $longest = max($longest, mb_strlen($name));
        }

        $this->assertSame(158, $longest, '§A1.9 measured OP7 at 158 characters; if this moved, the freeze needs re-reading');
    }

    #[Test]
    public function each_child_carries_its_basel_level_one_mapping_in_its_description(): void
    {
        // §A1.9: the mapping goes into `description`, which "is already there, rendered and
        // tenant-editable" — refused were a new column and a config file for it. Spec 04 §2 has
        // the presenter extract `basel_level_1` from exactly this string, so the format is a
        // contract rather than a comment.
        $this->catalogue->ensureFor($this->org);

        $expected = [
            1 => 'Internal fraud',
            2 => 'External fraud',
            3 => 'Employment practices & workplace safety',
            4 => 'Employment practices & workplace safety',
            5 => 'Employment practices & workplace safety',
            6 => 'Clients, products & business practices',
            7 => 'Clients, products & business practices',
            8 => 'Clients, products & business practices',
            9 => 'Damage to physical assets',
            10 => 'External fraud',
            11 => 'Business disruption & system failures',
            12 => 'Execution, delivery & process management',
            13 => 'Execution, delivery & process management',
            15 => 'Execution, delivery & process management',
            16 => 'Execution, delivery & process management',
            17 => 'Execution, delivery & process management',
            14 => 'Clients, products & business practices',
        ];

        foreach (RiskTaxonomy::query()->where('organization_id', $this->org->id)->whereNotNull('parent_id')->get() as $node) {
            $number = (int) $node->sort_order;

            $this->assertSame(
                OpEventTypeCatalogue::BASEL_DESCRIPTION_PREFIX.$expected[$number],
                $node->description,
                "OP{$number} description"
            );

            $this->assertSame(
                $expected[$number],
                OpEventTypeCatalogue::baselLevel1From($node->description),
                "OP{$number} extraction"
            );
        }
    }

    #[Test]
    public function the_extraction_returns_null_when_a_tenant_has_edited_the_description(): void
    {
        // Spec 04 §2: null "when a tenant has edited that description, and the picker falls back
        // to a flat list". Guessing a grouping would be worse than showing none.
        $this->assertNull(OpEventTypeCatalogue::baselLevel1From(null));
        $this->assertNull(OpEventTypeCatalogue::baselLevel1From(''));
        $this->assertNull(OpEventTypeCatalogue::baselLevel1From('Our own wording for this event type.'));
        $this->assertNull(OpEventTypeCatalogue::baselLevel1From(OpEventTypeCatalogue::BASEL_DESCRIPTION_PREFIX));
        $this->assertSame(
            'Internal fraud',
            OpEventTypeCatalogue::baselLevel1From('Basel Level-1: Internal fraud')
        );
    }

    #[Test]
    public function re_running_adds_nothing_and_changes_nothing(): void
    {
        $this->catalogue->ensureFor($this->org);
        $before = $this->snapshot();

        $second = $this->catalogue->ensureFor($this->org);
        $third = $this->catalogue->ensureFor($this->org);

        $this->assertSame(['created' => 0, 'repaired' => 0, 'total' => 17], $second);
        $this->assertSame(['created' => 0, 'repaired' => 0, 'total' => 17], $third);
        $this->assertSame($before, $this->snapshot(), 'a re-run must be a no-op on an untouched tree');
        $this->assertSame(18, RiskTaxonomy::query()->where('organization_id', $this->org->id)->count());
    }

    #[Test]
    public function re_running_does_not_resurrect_a_node_a_tenant_retired(): void
    {
        // §A1.9's one line that could undo a tenant's edit: "Do NOT use updateOrCreate with
        // `is_active` in the attributes half — that re-activates a node a tenant retired."
        $this->catalogue->ensureFor($this->org);

        $retired = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', 'like', 'OP13%')
            ->first();

        $retired->forceFill(['is_active' => false])->save();

        $result = $this->catalogue->ensureFor($this->org);

        $this->assertSame(0, $result['created']);
        $this->assertFalse(
            (bool) $retired->fresh()->is_active,
            'a retired node must stay retired; re-activating it silently reverses a governance decision'
        );
    }

    #[Test]
    public function re_running_keeps_a_tenants_own_rename_and_repairs_only_broken_structure(): void
    {
        $this->catalogue->ensureFor($this->org);

        // A bank wanting "OP13 Failures in credit or claims processes" edits its own node and
        // keeps the OP number (§A1.9). The rename must survive a re-run — which it does, because
        // the natural key is the ORIGINAL name and a renamed node simply stops matching, so the
        // seeder re-creates the original beside it. That is the honest behaviour: a rename is a
        // tenant's decision and the catalogue stays complete.
        $node = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', 'like', 'OP13%')
            ->first();

        $node->forceFill(['name' => 'OP13 Failures in credit or claims processes'])->save();

        $result = $this->catalogue->ensureFor($this->org);

        $this->assertSame(1, $result['created'], 'the renamed node no longer matches the natural key, so the original is restored');
        $this->assertNotNull(
            RiskTaxonomy::query()->where('organization_id', $this->org->id)
                ->where('name', 'OP13 Failures in credit or claims processes')->first(),
            "the tenant's rename must survive"
        );

        // A broken parent link IS repaired, because that is structure rather than wording.
        $orphan = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', 'like', 'OP9%')
            ->first();

        $orphan->forceFill(['parent_id' => null, 'depth' => 0, 'sort_order' => 0])->save();

        $repaired = $this->catalogue->ensureFor($this->org);

        $this->assertSame(1, $repaired['repaired']);
        $this->assertNotNull($orphan->fresh()->parent_id);
        $this->assertSame(1, (int) $orphan->fresh()->depth);
        $this->assertSame(9, (int) $orphan->fresh()->sort_order);
    }

    #[Test]
    public function it_seeds_every_organisation_and_keeps_their_trees_apart(): void
    {
        // "Seed every organization present in the system" — the shape BusinessProcessSeeder set.
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->seed(\Database\Seeders\Sm\SmReferenceSeeder::class);

        foreach ([$this->org, $other] as $organization) {
            $this->assertSame(
                18,
                RiskTaxonomy::query()->where('organization_id', $organization->id)->count(),
                "{$organization->name} should have one parent plus 17 OP nodes"
            );
        }

        // And a rename in one tenant is invisible to the other — which is the point of per-tenant
        // rows rather than shared system rows.
        RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', 'like', 'OP8%')
            ->update(['name' => 'OP8 Product flaws (our wording)']);

        $this->assertSame(
            'OP8 Product flaws',
            RiskTaxonomy::query()->where('organization_id', $other->id)->where('name', 'like', 'OP8%')->value('name')
        );
    }

    #[Test]
    public function seeding_a_second_tenant_while_the_first_is_the_ambient_context_does_not_duplicate_it(): void
    {
        // The bug `TenantContext::actingAs()` inside `ensureFor()` exists to prevent.
        // `RiskTaxonomy` carries BelongsToOrganization, whose global scope filters to the AMBIENT
        // tenant — so without it, seeding org B while the context holds org A finds none of B's
        // existing nodes and creates a second full set on every run.
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->catalogue->ensureFor($other);
        $this->catalogue->ensureFor($other);

        TenantContext::set($this->org->id);

        $this->catalogue->ensureFor($other);
        $this->catalogue->ensureFor($other);

        // Read through a bypass: `RiskTaxonomy` carries BelongsToOrganization, so an ordinary
        // query here would be scoped to the AMBIENT tenant and count zero of the other bank's
        // rows — which would make the assertion pass for the wrong reason.
        TenantContext::bypass(function () use ($other): void {
            $this->assertSame(
                18,
                RiskTaxonomy::query()->where('organization_id', $other->id)->count(),
                'seeding through a mismatched ambient tenant must not duplicate the tree'
            );
            $this->assertSame(
                0,
                RiskTaxonomy::query()->where('organization_id', $this->org->id)->count(),
                'and it must not write into the ambient tenant either'
            );
        }, 'Sm seeder test: counting another tenant\'s rows to prove nothing was duplicated');

        // And the ambient tenant is restored afterwards, so a caller inside a request is
        // unaffected by the actingAs() wrapper.
        $this->assertSame($this->org->id, TenantContext::organizationId());
    }

    #[Test]
    public function the_artisan_command_seeds_one_tenant_or_all_of_them(): void
    {
        // §A1.9: "Phase 1 also exposes `php artisan sm:seed-op-event-types {--organization=}`",
        // because deploy.sh runs RolesAndPermissionsSeeder ONLY and no code path in this
        // repository creates an Organization — so a tenant provisioned after the last deploy has
        // no other way to get its picker filled.
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->artisan('sm:seed-op-event-types', ['--organization' => $other->id])
            ->expectsOutputToContain('Other Bank PLC')
            ->assertSuccessful();

        $this->assertSame(18, RiskTaxonomy::query()->where('organization_id', $other->id)->count());
        $this->assertSame(0, RiskTaxonomy::query()->where('organization_id', $this->org->id)->count());

        $this->artisan('sm:seed-op-event-types')->assertSuccessful();

        $this->assertSame(18, RiskTaxonomy::query()->where('organization_id', $this->org->id)->count());
        // Idempotent through the command too.
        $this->assertSame(18, RiskTaxonomy::query()->where('organization_id', $other->id)->count());
    }

    #[Test]
    public function the_command_refuses_an_organisation_that_does_not_exist(): void
    {
        $this->artisan('sm:seed-op-event-types', ['--organization' => 987654])
            ->expectsOutputToContain('No organisation with id 987654')
            ->assertFailed();
    }

    #[Test]
    public function no_coso_components_are_seeded_because_no_phase_1_screen_reads_them(): void
    {
        // §A1.9: "COSO components are NOT seeded in Phase 1 — D3 names them but they belong to
        // `sm_scorecards` (Phase 2) and no Phase 1 screen reads them." BcmsReferenceSeeder's
        // docblock is the standing reason: a seeder that writes data no screen reads is data a
        // customer did not ask for.
        $this->catalogue->ensureFor($this->org);

        $names = RiskTaxonomy::query()->where('organization_id', $this->org->id)->pluck('name')->all();

        foreach (['Control environment', 'Risk assessment', 'Control activities', 'Information and Communication', 'Monitoring'] as $coso) {
            $this->assertNotContains($coso, $names, "COSO component '{$coso}' is Phase 2's, not Phase 1's");
        }

        // Only the Basel III OP tree, and nothing else.
        $this->assertSame(
            ['Basel III'],
            RiskTaxonomy::query()->where('organization_id', $this->org->id)->distinct()->pluck('framework')->all()
        );
    }

    #[Test]
    public function nodes_for_reads_the_taxonomy_so_a_tenant_extension_appears_without_a_code_change(): void
    {
        // Spec 02 §5.4: "the picker reads the taxonomy, never a constant, or an extension would be
        // invisible." A CBN ORMS sub-type a tenant adds through the existing taxonomy screen must
        // therefore reach the scenario form on its own.
        $this->catalogue->ensureFor($this->org);

        $parent = RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->where('name', OpEventTypeCatalogue::PARENT_NAME)
            ->sole();

        $before = $this->catalogue->nodesFor($this->org->id)->count();

        TenantContext::actingAs($this->org->id, fn () => RiskTaxonomy::create([
            'organization_id' => $this->org->id,
            'name' => 'OP18 CBN ORMS sub-type',
            'description' => 'Tenant extension.',
            'framework' => OpEventTypeCatalogue::FRAMEWORK,
            'parent_id' => $parent->id,
            'sort_order' => 18,
            'depth' => 1,
            'is_active' => true,
        ]));

        $nodes = $this->catalogue->nodesFor($this->org->id);

        $this->assertSame($before + 1, $nodes->count());
        $this->assertSame('OP18 CBN ORMS sub-type', $nodes->last()->name);
        $this->assertSame(range(1, 18), $nodes->pluck('sort_order')->map(fn ($n) => (int) $n)->all());

        // A retired node drops out of the picker without being deleted.
        $nodes->last()->forceFill(['is_active' => false])->save();

        $this->assertSame($before, $this->catalogue->nodesFor($this->org->id)->count());
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshot(): array
    {
        return RiskTaxonomy::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('id')
            ->get(['name', 'description', 'framework', 'parent_id', 'sort_order', 'depth', 'is_active'])
            ->map(fn (RiskTaxonomy $node) => $node->only(['name', 'description', 'framework', 'parent_id', 'sort_order', 'depth', 'is_active']))
            ->all();
    }
}
