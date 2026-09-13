<?php

namespace Tests\Feature\Emerging;

use App\Models\EmergingRisk;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use App\Policies\EmergingRiskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The emerging risk register's create and edit pages (migration Phase 4.6),
 * and the three defects the port found.
 *
 * The largest of them is not subtle: THE CREATE FORM COULD NOT SAVE. Its
 * horizon, potential_impact and status selects were hand-written in
 * FormFieldRegistry and none of the three matched the enum column it maps to,
 * so submitting exactly what the form offered came back with errors on two
 * required fields whose every option was invalid. Nothing caught it because
 * RiskIntelligenceGateTest posts the route directly with correct values and
 * never asks the form what it is offering. These tests do.
 */
class EmergingRiskPagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Horizon Bank PLC', 'short_name' => 'HZN',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Chief Risk Officer',
            'email' => 'cro@horizon.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->user->assignRole('chief-risk-officer');
    }

    /* ------------------------------------------------------------------ */
    /*  The form could not save */
    /* ------------------------------------------------------------------ */

    /**
     * The regression test for the whole defect: ask the form what it offers,
     * then submit that. It has to be accepted.
     */
    #[Test]
    public function submitting_exactly_what_the_create_form_offers_is_accepted(): void
    {
        $offered = $this->optionsOnTheCreateForm();

        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), [
                'title' => 'Quantum decryption of archived records',
                'horizon' => $offered['horizon'][0],
                'potential_impact' => $offered['potential_impact'][0],
                'status' => $offered['status'][0],
                'velocity_score' => 4,
                'proximity_score' => 3,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('risk.emerging.index'));

        $this->assertSame(1, EmergingRisk::count());
    }

    /**
     * And the reason it now holds: the options are DERIVED from the model's
     * constants rather than retyped beside them.
     */
    #[Test]
    public function every_option_the_form_offers_is_a_value_the_column_accepts(): void
    {
        $offered = $this->optionsOnTheCreateForm();

        $this->assertSame(EmergingRisk::HORIZONS, $offered['horizon']);
        $this->assertSame(EmergingRisk::IMPACTS, $offered['potential_impact']);
        $this->assertSame(EmergingRisk::STATUSES, $offered['status']);
    }

    /** Every one of them, not just the first — one at a time, through the form. */
    #[Test]
    public function each_offered_combination_saves(): void
    {
        $offered = $this->optionsOnTheCreateForm();

        foreach ($offered['horizon'] as $index => $horizon) {
            $this->actingAs($this->user)
                ->post(route('risk.emerging.store'), [
                    'title' => "Horizon probe {$horizon}",
                    'horizon' => $horizon,
                    'potential_impact' => $offered['potential_impact'][$index] ?? $offered['potential_impact'][0],
                    'status' => $offered['status'][$index] ?? $offered['status'][0],
                    'velocity_score' => 3,
                    'proximity_score' => 3,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(count($offered['horizon']), EmergingRisk::count());
    }

    /* ------------------------------------------------------------------ */
    /*  The pages */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_create_page_is_driven_by_the_object_type(): void
    {
        $this->actingAs($this->user)
            ->get(route('risk.emerging.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emerging/Create')
                ->where('schema.objectType.code', 'EmergingRisk')
                ->has('schema.sections'));
    }

    #[Test]
    public function the_edit_page_carries_the_entry_and_its_schema(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->user)
            ->get(route('risk.emerging.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emerging/Edit')
                ->where('entry.reference', $entry->reference)
                ->where('entry.title', $entry->title)
                // radar_score is an accessor, velocity × proximity.
                ->where('entry.radarScore', 12)
                ->where('schema.objectType.code', 'EmergingRisk')
                ->etc());
    }

    #[Test]
    public function marking_an_entry_reviewed_records_today(): void
    {
        $entry = $this->entry(['last_reviewed_at' => null]);

        $this->actingAs($this->user)
            ->post(route('risk.emerging.review', $entry))
            ->assertRedirect();

        $this->assertSame(now()->toDateString(), $entry->fresh()->last_reviewed_at?->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /*  Tenant-added fields */
    /* ------------------------------------------------------------------ */

    /**
     * Before Phase 4.6 this was impossible. EmergingRisk was absent from
     * ObjectTypeRegistry::modelTypeMap() and carried no HasObjectIdentity, so
     * PersistsConfiguredAttributes could not resolve a type, returned 0 before
     * validating anything, and the value was discarded without a word — the
     * exact failure that trait's docblock says it exists to prevent.
     */
    #[Test]
    public function a_field_a_tenant_added_through_the_builder_is_stored(): void
    {
        $this->addTenantField();

        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), $this->payload([
                'configured_attributes' => ['regulatory_driver' => 'CBN circular 2026/04'],
            ]))
            ->assertSessionHasNoErrors();

        $entry = EmergingRisk::firstOrFail();

        $this->assertSame(
            'CBN circular 2026/04',
            $entry->graphObject()?->customAttributes()['regulatory_driver'] ?? null,
        );
    }

    /**
     * And it is validated BEFORE the record is written.
     *
     * The controller used to create the row and only then call
     * saveConfiguredAttributes(), which validates on its own — so a required
     * tenant field left blank threw after `EmergingRisk::create()` had already
     * run and consumed a reference from the per-tenant sequence. The user was
     * told the field was required, filled it in, submitted again, and the
     * register had two entries.
     */
    #[Test]
    public function a_failed_tenant_field_leaves_nothing_behind(): void
    {
        $this->addTenantField(['is_required' => true]);

        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), $this->payload([
                'configured_attributes' => ['regulatory_driver' => ''],
            ]))
            ->assertSessionHasErrors('configured_attributes.regulatory_driver');

        $this->assertSame(0, EmergingRisk::count(), 'A rejected submission must not leave an entry on the register.');

        // The user fixes it and resubmits: exactly one entry, not two.
        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), $this->payload([
                'configured_attributes' => ['regulatory_driver' => 'CBN circular 2026/04'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EmergingRisk::count());
    }

    /** The same, on update: a rejected amendment must change nothing. */
    #[Test]
    public function a_failed_amendment_writes_no_columns(): void
    {
        $this->addTenantField(['is_required' => true]);
        $entry = $this->entry();

        $this->actingAs($this->user)
            ->put(route('risk.emerging.update', $entry), $this->payload([
                'title' => 'RENAMED BY A REQUEST THAT FAILED',
                'configured_attributes' => ['regulatory_driver' => ''],
            ]))
            ->assertSessionHasErrors('configured_attributes.regulatory_driver');

        $this->assertSame('Sudden FX policy reversal', $entry->fresh()->title);
    }

    /* ------------------------------------------------------------------ */
    /*  Policy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_policy_is_discovered_and_asks_for_the_risk_permissions(): void
    {
        $this->assertInstanceOf(EmergingRiskPolicy::class, Gate::getPolicyFor(EmergingRisk::class));

        $entry = $this->entry();

        // risk.*, not emerging.* — the set the routes have always carried.
        $viewer = $this->userWith(['risk.view']);
        $editor = $this->userWith(['risk.view', 'risk.edit']);
        $deleter = $this->userWith(['risk.view', 'risk.delete']);

        $this->assertTrue($viewer->can('view', $entry));
        $this->assertFalse($viewer->can('update', $entry));
        $this->assertFalse($viewer->can('delete', $entry));

        $this->assertTrue($editor->can('update', $entry));
        $this->assertTrue($editor->can('review', $entry));
        $this->assertFalse($editor->can('delete', $entry));

        $this->assertTrue($deleter->can('delete', $entry));
    }

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $other = Organization::create([
            'name' => 'Rival Bank PLC', 'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $foreign = EmergingRisk::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'reference' => 'EMR-2026-9999',
            'title' => 'Theirs',
            'horizon' => '0-3m',
            'velocity_score' => 3,
            'proximity_score' => 3,
            'potential_impact' => 'Medium',
            'status' => 'monitoring',
        ]);

        $user = $this->userWith(['risk.view', 'risk.edit', 'risk.delete']);

        foreach (['view', 'update', 'delete', 'review'] as $ability) {
            $this->assertFalse($user->can($ability, $foreign), "{$ability} denied across tenants");
        }

        // And the route is not found, because binding is scoped.
        $this->actingAs($user)
            ->get(route('risk.emerging.edit', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function an_emerging_risk_cannot_be_attached_to_another_tenants_category(): void
    {
        $other = Organization::create([
            'name' => 'Rival Bank PLC', 'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $foreignCategory = RiskCategory::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'code' => 'FGN', 'name' => 'Foreign category',
        ]);

        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), $this->payload(['category_id' => $foreignCategory->id]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, EmergingRisk::count());
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, list<string>> */
    private function optionsOnTheCreateForm(): array
    {
        $props = $this->actingAs($this->user)
            ->get(route('risk.emerging.create'))
            ->assertOk()
            ->viewData('page')['props'];

        $fields = collect($props['schema']['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->keyBy('code');

        $values = fn (string $code) => collect($fields[$code]['options'] ?? [])
            ->map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option)
            ->values()
            ->all();

        return [
            'horizon' => $values('horizon'),
            'potential_impact' => $values('potential_impact'),
            'status' => $values('status'),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sudden FX policy reversal',
            'horizon' => '0-3m',
            'velocity_score' => 4,
            'proximity_score' => 3,
            'potential_impact' => 'Critical',
            'status' => 'monitoring',
        ], $overrides);
    }

    private function entry(array $overrides = []): EmergingRisk
    {
        return EmergingRisk::create(array_merge([
            'organization_id' => $this->org->id,
            'reference' => 'EMR-2026-0001',
            'title' => 'Sudden FX policy reversal',
            'horizon' => '0-3m',
            'velocity_score' => 4,
            'proximity_score' => 3,
            'potential_impact' => 'Critical',
            'status' => 'monitoring',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    private function addTenantField(array $overrides = []): ObjectAttribute
    {
        return ObjectAttribute::create(array_merge([
            'object_type_id' => ObjectType::resolve('EmergingRisk')->id,
            'code' => 'regulatory_driver',
            'label' => 'Regulatory Driver',
            'data_type' => 'string',
            'section' => 'Evidence',
            'sort_order' => 500,
            'is_system' => false,
        ], $overrides));
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        static $n = 0;

        $user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Holder '.++$n,
            'email' => 'holder-'.$n.'-'.$this->org->id.'@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->givePermissionTo($permissions);

        return $user->fresh();
    }
}
