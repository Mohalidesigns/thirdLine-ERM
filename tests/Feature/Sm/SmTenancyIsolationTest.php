<?php

namespace Tests\Feature\Sm;

use App\Models\Risk;
use App\Models\Sm\BusinessLineAllocation;
use App\Models\Sm\Note;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\Signoff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Tenancy isolation on every Sm route (standard §7, gate checklist §B).
 *
 * TWO PROPERTIES, AND THE SECOND IS THE ONE THAT USUALLY GETS MISSED.
 *
 *   1. A cross-tenant id is a 404, never a 403 and never a 200. 404 specifically: the
 *      caller here holds every permission, so a 403 would mean some OTHER check refused the
 *      request and the tenant scope was never exercised.
 *   2. THERE IS NO EXISTENCE ORACLE. A foreign id and an id that exists nowhere must produce
 *      the SAME status code, or the endpoint answers — one id at a time — a question the
 *      caller is not entitled to ask. Standard §4 found exactly this on four AI endpoints.
 *
 * The mechanism is not a check in the controller: `ResolveTenant` runs BEFORE
 * `SubstituteBindings` (bootstrap/app.php), so `BelongsToOrganization`'s global scope has
 * already excluded the other tenant's row by the time the binding resolves. Nothing here needs
 * to be remembered per route, which is why the provider lists all eight `{scenario}` routes and
 * asserts them all the same way.
 */
class SmTenancyIsolationTest extends SmTestCase
{
    /** An id in a range nothing will ever reach, so "does not exist" is unambiguous. */
    private const NONEXISTENT_ID = 999999;

    #[Test]
    #[DataProvider('scenarioRouteProvider')]
    public function another_tenants_scenario_is_not_found_rather_than_forbidden(string $method, string $routeName, array $body): void
    {
        $foreign = $this->foreignScenario->id;

        $foreignResponse = $this->actingAs($this->actor)
            ->json($method, route($routeName, ['scenario' => $foreign]), $body);

        $this->assertSame(
            404,
            $foreignResponse->getStatusCode(),
            "{$method} {$routeName} with another tenant's id returned {$foreignResponse->getStatusCode()}"
        );

        // NO EXISTENCE ORACLE: the same route with an id that exists nowhere must answer
        // identically. A 404 for a foreign row and a 403 or 500 for a missing one is a
        // membership test an outsider can run for free.
        $missingResponse = $this->actingAs($this->actor)
            ->json($method, route($routeName, ['scenario' => self::NONEXISTENT_ID]), $body);

        $this->assertSame(
            $foreignResponse->getStatusCode(),
            $missingResponse->getStatusCode(),
            "{$method} {$routeName} distinguishes another tenant's row from a row that does not exist"
        );
    }

    /**
     * Every route that binds `{scenario}`, with a body that would otherwise be valid.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function scenarioRouteProvider(): array
    {
        return [
            'show' => ['GET', 'scenario.show', []],
            'edit' => ['GET', 'scenario.edit', []],
            'update' => ['PUT', 'scenario.update', ['name' => 'Taken over']],
            'causes' => ['PUT', 'scenario.causes.update', ['causes' => ['people_other' => ['note' => 'Taken over']]]],
            'allocations' => ['PUT', 'scenario.allocations.update', ['allocations' => [['business_unit_id' => 1, 'percent' => '100.00']]]],
            'status' => ['POST', 'scenario.status', ['to' => 'in_design']],
            'signoffs' => ['POST', 'scenario.signoffs.store', ['signoffs' => [], 'review_due_on' => '2027-11-10']],
            'notes' => ['POST', 'scenario.notes.store', ['kind' => 'dissent', 'body' => 'A dissent on somebody else\'s scenario.']],
        ];
    }

    #[Test]
    public function no_write_reaches_another_tenants_row(): void
    {
        // The 404s above prove the binding refused; this proves nothing landed either, so a
        // future refactor that resolves first and authorises second still fails here.
        $foreign = $this->foreignScenario;
        $originalName = $foreign->name;

        $this->actingAs($this->actor)->put(route('scenario.update', ['scenario' => $foreign->id]), ['name' => 'Overwritten by another bank']);
        $this->actingAs($this->actor)->post(route('scenario.notes.store', ['scenario' => $foreign->id]), ['kind' => 'dissent', 'body' => 'Injected dissent.']);
        $this->actingAs($this->actor)->post(route('scenario.status', ['scenario' => $foreign->id]), ['to' => 'archived']);

        TenantContext::bypass(function () use ($foreign, $originalName): void {
            $fresh = $foreign->fresh();

            $this->assertSame($originalName, $fresh->name);
            $this->assertSame('draft', $fresh->status);
            $this->assertSame(0, $fresh->notes()->count());
            $this->assertNull($fresh->deleted_at);
        }, 'Sm tenancy test: verifying the other tenant\'s row is untouched');
    }

    #[Test]
    public function the_index_lists_only_the_callers_own_scenarios(): void
    {
        $mine = $this->makeScenario(['name' => 'Alpha visible scenario']);

        $this->actingAs($this->actor)
            ->get(route('scenario.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.scenarios', 1)
                ->where('grid.rows.data', function ($rows) use ($mine) {
                    $this->assertCount(1, $rows);
                    $this->assertSame((string) $mine->id, $rows[0]['id']);
                    $this->assertStringNotContainsString('Another bank', json_encode($rows));

                    return true;
                }));
    }

    #[Test]
    public function the_risk_search_does_not_reach_another_tenants_register(): void
    {
        // The register search is the one Sm endpoint that reads a table another module owns, so
        // it is the one place a missing tenant predicate would leak rows rather than ids.
        $mine = Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RK-SM-0001',
            'title' => 'Failure to retain critical talent',
            'description' => 'Ours.',
            'category_id' => $this->category->id,
            'status' => 'active',
        ]);

        TenantContext::bypass(function (): void {
            Risk::create([
                'organization_id' => $this->otherOrg->id,
                'risk_code' => 'RK-SM-9999',
                'title' => 'Failure to retain critical talent at the other bank',
                'description' => 'Theirs.',
                'category_id' => \App\Models\RiskCategory::create([
                    'organization_id' => $this->otherOrg->id,
                    'code' => 'OPS',
                    'name' => 'Operational Risk',
                ])->id,
                'status' => 'active',
            ]);
        }, 'Sm tenancy test: a foreign risk to search for');

        $response = $this->actingAs($this->actor)
            ->getJson(route('scenario.risk-search', ['q' => 'critical talent']))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertStringNotContainsString('RK-SM-9999', $response->getContent());
    }

    #[Test]
    public function a_child_row_belonging_to_another_tenant_cannot_be_attached_through_a_parent_route(): void
    {
        // Standard §4's other half: every foreign key in every Form Request is tenant-bound, so
        // a business unit from the other tenant is refused with the same message whether or not
        // it exists — and the refusal is a 422 rather than a probe answer.
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $foreignUnitId = TenantContext::bypass(
            fn () => $this->makeForeignUnit(),
            'Sm tenancy test: a business unit in the other tenant'
        );

        $this->actingAs($this->actor)
            ->put(route('scenario.allocations.update', $scenario), [
                'allocations' => [['business_unit_id' => $foreignUnitId, 'percent' => '100.00']],
            ])
            ->assertSessionHasErrors(['allocations.0.business_unit_id' => "That business unit isn't in your organisation."]);

        $this->assertSame(0, $scenario->allocations()->count());

        // And the same message for an id that exists nowhere: no oracle.
        $this->actingAs($this->actor)
            ->put(route('scenario.allocations.update', $scenario), [
                'allocations' => [['business_unit_id' => self::NONEXISTENT_ID, 'percent' => '100.00']],
            ])
            ->assertSessionHasErrors(['allocations.0.business_unit_id' => "That business unit isn't in your organisation."]);

        $this->assertSame(0, $scenario->allocations()->count());
    }

    #[Test]
    public function every_sm_model_is_covered_by_the_shared_tenancy_guard(): void
    {
        // TenancyIsolationTest enumerates every model carrying BelongsToOrganization and runs
        // the index/show/update/delete probes against it through TenantFixture. This asserts the
        // five Sm models are in that set, so the shared guard cannot silently stop covering them.
        foreach ([
            \App\Models\Sm\Scenario::class,
            ScenarioCause::class,
            BusinessLineAllocation::class,
            Signoff::class,
            Note::class,
        ] as $class) {
            $this->assertContains(
                \ThirdLine\Platform\Tenancy\BelongsToOrganization::class,
                class_uses_recursive($class),
                "{$class} must carry BelongsToOrganization or the shared isolation guard skips it"
            );
        }
    }

    #[Test]
    public function only_the_aggregate_root_carries_a_uuid(): void
    {
        // Backend rule 6: bigint id plus a unique uuid ON AGGREGATE ROOTS ONLY. Child and line
        // tables carry no uuid — and §A1.11 froze `uuid` now so the Phase 4 graph mirror needs
        // no migration, which is the only reason it exists in Phase 1 at all.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('sm_scenarios', 'uuid'));

        foreach (['sm_scenario_causes', 'sm_business_line_allocations', 'sm_scenario_signoffs', 'sm_scenario_notes'] as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn($table, 'uuid'),
                "{$table} is a child line table and must not carry a uuid"
            );
        }

        $scenario = $this->makeScenario();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $scenario->uuid,
            'the creating hook must stamp a uuid without the caller asking'
        );
    }

    private function makeForeignUnit(): int
    {
        return \App\Models\BusinessUnit::create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'BU-OTH',
            'name' => 'Other Bank Treasury',
            'is_active' => true,
        ])->id;
    }
}
