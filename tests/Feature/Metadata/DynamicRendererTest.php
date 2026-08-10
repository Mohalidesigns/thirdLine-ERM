<?php

namespace Tests\Feature\Metadata;

use App\Models\Control;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\ScoringProfile;
use App\Services\Scoring\ScoringProfileProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-05 TASK 2 acceptance — the five create/edit pairs render from metadata,
 * and a field added through the builder is genuinely usable end to end.
 *
 * The test that matters most is the last group: a field configured through the
 * builder must render on the form, survive the round trip, and be refused when
 * the user is not entitled to it. A renderer that shows a field it then
 * discards is worse than one that never showed it.
 */
class DynamicRendererTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        ScoringProfile::flushResolutionCache();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
    }

    protected function tearDown(): void
    {
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The five pairs render */
    /* ------------------------------------------------------------------ */

    public static function convertedTypes(): array
    {
        return [
            'Control' => ['Control', 9],
            // 13, not 14: `category` is deliberately absent. See the note in
            // FormFieldRegistry::kri().
            'KeyRiskIndicator' => ['KeyRiskIndicator', 13],
            'TreatmentPlan' => ['TreatmentPlan', 16],
            'Issue' => ['Issue', 21],
            'EmergingRisk' => ['EmergingRisk', 14],
        ];
    }

    #[Test]
    #[DataProvider('convertedTypes')]
    public function each_converted_type_has_column_backed_field_definitions(string $code, int $expectedCount): void
    {
        $type = ObjectType::resolve($code);

        $this->assertNotNull($type, "the {$code} object type should exist in the registry");

        $fields = $type->attributeDefinitions;

        $this->assertCount($expectedCount, $fields, "{$code} should have {$expectedCount} configured fields");

        foreach ($fields as $field) {
            $this->assertTrue(
                $field->isMapped(),
                "{$code}.{$field->code} should map to a real column — an unmapped system field would be a "
                .'second definition of storage that already exists'
            );

            // Either a real column, or a name the controller fans out onto
            // several columns and the model folds back through an accessor.
            // Anything else is a field that renders, accepts what is typed and
            // then discards it.
            $isColumn = \Illuminate\Support\Facades\Schema::hasColumn(
                $this->tableFor($code),
                $field->maps_to_column
            );

            $this->assertTrue(
                $isColumn || in_array($field->maps_to_column, self::DERIVED_FIELDS[$code] ?? [], true),
                "{$code}.{$field->code} maps to {$field->maps_to_column}, which is neither a column on "
                .$this->tableFor($code).' nor a declared derived field'
            );
        }
    }

    /**
     * Fields whose posted name is not a column: KriController fans them out
     * across the min/max band columns on the way in, and accessors on the
     * model fold them back on the way out. Listed explicitly so that a
     * genuinely broken mapping cannot hide among them.
     *
     * @var array<string, list<string>>
     */
    private const DERIVED_FIELDS = [
        'KeyRiskIndicator' => ['green_threshold', 'amber_threshold', 'red_threshold', 'formula'],
    ];

    private function tableFor(string $code): string
    {
        return match ($code) {
            'Control' => 'controls',
            'KeyRiskIndicator' => 'key_risk_indicators',
            'TreatmentPlan' => 'treatment_plans',
            'Issue' => 'issues',
            'EmergingRisk' => 'emerging_risks',
        };
    }

    #[Test]
    public function the_control_create_form_renders_every_configured_field(): void
    {
        $response = $this->actingAs($this->actor)->get(route('risk.controls.create'));

        $response->assertOk();

        foreach (ObjectType::resolve('Control')->attributeDefinitions as $field) {
            $response->assertSee('name="'.$field->maps_to_column.'"', false);
            $response->assertSee($field->label, false);
        }
    }

    #[Test]
    public function the_control_edit_form_is_prefilled_from_the_record(): void
    {
        $control = $this->makeControl([
            'name' => 'Dual authorisation on wire transfers',
            'control_type' => 'preventive',
            'effectiveness_rating' => 'effective',
        ]);

        $response = $this->actingAs($this->actor)->get(route('risk.controls.edit', $control));

        $response->assertOk();
        $response->assertSee('Dual authorisation on wire transfers', false);
        // The stored enum renders as the selected option, not merely present.
        $response->assertSee('value="preventive" selected', false);
    }

    #[Test]
    public function a_control_still_saves_through_the_untouched_controller(): void
    {
        // The point of rendering rather than rewriting: the write path,
        // its reference-code generation and its validation are unchanged.
        $response = $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Daily suspense account reconciliation',
            'description' => 'Operations reconciles all suspense accounts daily against the general ledger.',
            'control_type' => 'detective',
            'owner_id' => $this->actor->id,
            'status' => 'active',
        ]);

        $response->assertRedirect();

        $control = Control::where('name', 'Daily suspense account reconciliation')->first();

        $this->assertNotNull($control);
        $this->assertSame('detective', $control->control_type);
        $this->assertNotNull($control->control_code, 'the controller still generates the reference code');
    }

    #[Test]
    public function a_form_offers_the_organizations_own_scoring_scale_not_a_hardcoded_five(): void
    {
        app(ScoringProfileProvisioner::class)->resize($this->organization, 4, 4);
        ScoringProfile::flushResolutionCache();

        $response = $this->actingAs($this->actor)->get(route('risk.treatments.create'));

        $response->assertOk();
        $response->assertSee('name="expected_residual_impact"', false);
        // A 4×4 organisation must not be offered a 5 its matrix cannot hold.
        $response->assertDontSee('<option value="5" >5 —', false);
    }

    /* ------------------------------------------------------------------ */
    /*  A field added through the builder works end to end */
    /* ------------------------------------------------------------------ */

    private function addTenantField(array $overrides = []): ObjectAttribute
    {
        return ObjectAttribute::create(array_merge([
            'object_type_id' => ObjectType::resolve('Control')->id,
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract', 'legal_obligation'],
            'section' => 'Details',
            'sort_order' => 500,
            'is_system' => false,
        ], $overrides));
    }

    #[Test]
    public function a_field_added_through_the_builder_appears_on_the_form(): void
    {
        $this->addTenantField();

        $response = $this->actingAs($this->actor)->get(route('risk.controls.create'));

        $response->assertOk();
        $response->assertSee('NDPR Lawful Basis', false);
        $response->assertSee('name="configured_attributes[ndpr_lawful_basis]"', false);
    }

    #[Test]
    public function a_field_added_through_the_builder_survives_the_round_trip(): void
    {
        $this->addTenantField();

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Customer consent capture',
            'description' => 'Consent is captured and timestamped at onboarding.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['ndpr_lawful_basis' => 'consent'],
        ])->assertRedirect();

        $control = Control::where('name', 'Customer consent capture')->firstOrFail();

        $this->assertSame(
            'consent',
            $control->graphObject()?->customAttributes()['ndpr_lawful_basis'] ?? null,
            'a configured field that renders on the form must actually be stored'
        );

        // And it comes back on the edit form.
        $this->actingAs($this->actor)
            ->get(route('risk.controls.edit', $control))
            ->assertSee('value="consent" selected', false);
    }

    #[Test]
    public function a_configured_field_is_validated_by_its_definition(): void
    {
        $this->addTenantField(['is_required' => true]);

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Missing the required configured field',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['ndpr_lawful_basis' => 'not_an_option'],
        ])->assertSessionHasErrors('configured_attributes.ndpr_lawful_basis');
    }

    #[Test]
    public function a_role_gated_field_is_neither_rendered_nor_accepted_from_a_user_without_the_role(): void
    {
        Role::findOrCreate('data-protection-officer');

        $this->addTenantField([
            'validation' => ['roles' => ['data-protection-officer']],
        ]);

        // The actor is a super-admin but does not hold that role.
        $this->actingAs($this->actor)
            ->get(route('risk.controls.create'))
            ->assertDontSee('configured_attributes[ndpr_lawful_basis]', false);

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Posting a field I may not see',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['ndpr_lawful_basis' => 'consent'],
        ])->assertRedirect();

        $control = Control::where('name', 'Posting a field I may not see')->firstOrFail();

        $this->assertArrayNotHasKey(
            'ndpr_lawful_basis',
            $control->graphObject()?->customAttributes() ?? [],
            'hiding a field in the browser is not a permission check; the server must refuse it too'
        );
    }

    #[Test]
    public function a_permission_gated_field_is_accepted_from_a_user_who_holds_it(): void
    {
        Permission::findOrCreate('control.edit');
        $this->actor->givePermissionTo('control.edit');

        $this->addTenantField(['validation' => ['permission' => 'control.edit']]);

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Posting a field I may set',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['ndpr_lawful_basis' => 'contract'],
        ])->assertRedirect();

        $control = Control::where('name', 'Posting a field I may set')->firstOrFail();

        $this->assertSame('contract', $control->graphObject()?->customAttributes()['ndpr_lawful_basis'] ?? null);
    }

    #[Test]
    public function a_key_the_metadata_does_not_declare_is_ignored_rather_than_stored(): void
    {
        $this->addTenantField();

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Posting an undeclared key',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => [
                'ndpr_lawful_basis' => 'consent',
                'something_nobody_configured' => 'should not be stored',
            ],
        ])->assertRedirect();

        $stored = Control::where('name', 'Posting an undeclared key')->firstOrFail()
            ->graphObject()?->customAttributes() ?? [];

        $this->assertSame('consent', $stored['ndpr_lawful_basis'] ?? null);
        $this->assertArrayNotHasKey('something_nobody_configured', $stored);
    }

    #[Test]
    public function a_money_field_is_entered_in_major_units_and_stored_in_minor_ones(): void
    {
        $this->addTenantField([
            'code' => 'annual_licence_cost',
            'label' => 'Annual Licence Cost',
            'data_type' => 'money',
            'enum_options' => null,
        ]);

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'A control with a cost',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['annual_licence_cost' => '2500.50'],
        ])->assertRedirect();

        $stored = Control::where('name', 'A control with a cost')->firstOrFail()
            ->graphObject()?->customAttributes() ?? [];

        $this->assertSame(250050, $stored['annual_licence_cost'], 'money is stored in minor units');
    }

    #[Test]
    public function a_formula_field_is_never_offered_as_an_input(): void
    {
        $this->addTenantField([
            'code' => 'computed_coverage',
            'label' => 'Computed Coverage',
            'data_type' => 'formula',
            'formula' => '1 + 1',
            'enum_options' => null,
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.controls.create'))
            ->assertDontSee('configured_attributes[computed_coverage]', false);
    }

    /* ------------------------------------------------------------------ */
    /*  Conditional visibility */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_conditional_field_carries_its_condition_into_the_markup(): void
    {
        // The examination reference is configured to appear only when the
        // issue source is a regulatory examination.
        $response = $this->actingAs($this->actor)->get(route('risk.issues.create'));

        $response->assertOk();

        // The rule travels into the markup as a live Alpine expression, not as
        // an @if that decided once at render time. That distinction is the
        // feature: the field has to appear the moment the source is switched,
        // without a round trip.
        $response->assertSee('x-show=', false);
        $response->assertSee('issue_source', false);
        $response->assertSee('regulatory', false);
    }

    /* ------------------------------------------------------------------ */
    /*  The detail renderer */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_detail_renderer_shows_labels_rather_than_stored_codes(): void
    {
        $control = $this->makeControl([
            'name' => 'Semi-automated reconciliation',
            'control_nature' => 'semi_automated',
        ]);

        $rendered = view('components.dynamic-detail', (new \App\View\Components\DynamicDetail(
            record: $control,
            type: 'Control',
        ))->data())->render();

        $this->assertStringContainsString('Semi-automated', $rendered);
        $this->assertStringNotContainsString('semi_automated', $rendered,
            'a detail view showing the stored code is technically accurate and useless');
    }

    #[Test]
    public function the_detail_renderer_marks_personal_data(): void
    {
        $this->addTenantField([
            'code' => 'reviewer_email',
            'label' => 'Reviewer Email',
            'data_type' => 'string',
            'enum_options' => null,
            'is_pii' => true,
        ]);

        $control = $this->makeControl();

        $rendered = view('components.dynamic-detail', (new \App\View\Components\DynamicDetail(
            record: $control,
            type: 'Control',
        ))->data())->render();

        $this->assertStringContainsString('Reviewer Email', $rendered);
        $this->assertStringContainsString('PII', $rendered);
    }
}
