<?php

namespace Tests\Feature\Metadata;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Control;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\ObjectVersion;
use App\Models\ScoringProfile;
use App\Presenters\FormSchemaPresenter;
use App\Services\RiskScoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2, 2.4 — the metadata form and detail renderers as props
 * for React, and the Form Request half of configured-attribute validation.
 *
 * The presenter is a projection of the two Blade components, so what these
 * tests defend is that the projection is faithful: the same fields, the same
 * post names, the same value rules, the same role gating, the same display
 * strings. A field the Blade renderer would hide must not appear here, or
 * the migration has quietly widened what a user can see.
 */
class FormSchemaPresenterTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private const FIELD_KEYS = [
        'code', 'name', 'errorKey', 'label', 'type', 'required', 'options', 'value',
        'help', 'width', 'pii', 'visibleWhen', 'mapped', 'readonly', 'formula',
    ];

    private FormSchemaPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        ScoringProfile::flushResolutionCache();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);

        $this->presenter = app(FormSchemaPresenter::class);
    }

    protected function tearDown(): void
    {
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  form(): shape and post names */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_data_type_comes_through_with_the_same_field_shape(): void
    {
        $this->addEveryBagType();

        $schema = $this->presenter->form('Control');

        $this->assertSame('Control', $schema['objectType']['code']);
        $this->assertArrayHasKey('id', $schema['objectType']);
        $this->assertArrayHasKey('name', $schema['objectType']);

        $fields = $this->flatten($schema);

        foreach ($fields as $field) {
            $this->assertSame(self::FIELD_KEYS, array_keys($field), "field {$field['code']} has the agreed shape");
        }

        // The registry's own types, mapped, plus every bag type a tenant can
        // configure through the builder.
        $types = array_unique(array_column($fields, 'type'));

        foreach ([
            'string', 'text', 'enum', 'int', 'date', 'money', 'bool', 'decimal',
            'multi_enum', 'json', 'datetime', 'user', 'object_ref', 'formula',
        ] as $expected) {
            $this->assertContains($expected, $types, "the schema carries a {$expected} field");
        }
    }

    #[Test]
    public function a_mapped_field_posts_under_its_column_and_an_unmapped_one_under_configured_attributes(): void
    {
        $this->addField(['code' => 'ndpr_lawful_basis', 'label' => 'NDPR Lawful Basis', 'data_type' => 'string']);

        $fields = $this->flatten($this->presenter->form('Control'));

        $name = $fields['name'];
        $this->assertTrue($name['mapped']);
        $this->assertSame('name', $name['name']);
        $this->assertSame('name', $name['errorKey']);
        $this->assertTrue($name['required']);
        $this->assertSame('full', $name['width']);
        $this->assertSame('string', $name['type']);

        $tenant = $fields['ndpr_lawful_basis'];
        $this->assertFalse($tenant['mapped']);
        $this->assertSame('configured_attributes[ndpr_lawful_basis]', $tenant['name']);
        $this->assertSame('configured_attributes.ndpr_lawful_basis', $tenant['errorKey']);
        $this->assertFalse($tenant['readonly']);
    }

    #[Test]
    public function fields_are_grouped_into_sections_and_a_section_filter_is_honoured(): void
    {
        $schema = $this->presenter->form('Control');

        $sections = array_column($schema['sections'], 'code');
        $this->assertContains('Details', $sections);
        $this->assertContains('Classification', $sections);
        $this->assertContains('Ownership', $sections);

        foreach ($schema['sections'] as $section) {
            $this->assertSame($section['code'], $section['label']);
        }

        $only = $this->presenter->form('Control', sections: ['Classification']);

        $this->assertCount(1, $only['sections']);
        $this->assertSame('Classification', $only['sections'][0]['code']);
        $this->assertArrayHasKey('control_type', $this->flatten($only));
        $this->assertArrayNotHasKey('name', $this->flatten($only));
    }

    #[Test]
    public function omit_drops_a_field_and_defaults_prefill_one(): void
    {
        $fields = $this->flatten($this->presenter->form('Control', omit: ['name'], defaults: ['status' => 'inactive']));

        $this->assertArrayNotHasKey('name', $fields);
        $this->assertSame('inactive', $fields['status']['value']);

        // Without a caller default the configured default wins.
        $this->assertSame('active', $this->flatten($this->presenter->form('Control'))['status']['value']);
    }

    /* ------------------------------------------------------------------ */
    /*  form(): options */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function options_are_resolved_with_their_labels(): void
    {
        $this->addField([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'legal_obligation'],
            'validation' => ['option_labels' => ['consent' => 'Consent given']],
        ]);
        $this->addField([
            'code' => 'reviewer_id',
            'label' => 'Reviewer',
            'data_type' => 'int',
            'validation' => ['options_source' => 'users'],
        ]);
        $this->addField([
            'code' => 'residual_likelihood',
            'label' => 'Residual Likelihood',
            'data_type' => 'int',
            'validation' => ['options_source' => 'scoring_scale', 'axis' => 'likelihood'],
        ]);
        $this->addField(['code' => 'approver', 'label' => 'Approver', 'data_type' => 'user']);
        $this->addField(['code' => 'free_text', 'label' => 'Free Text', 'data_type' => 'string']);

        $fields = $this->flatten($this->presenter->form('Control'));

        // A registry enum: stored code with the human label beside it.
        $this->assertContains(['value' => 'semi_automated', 'label' => 'Semi-automated'], $fields['control_nature']['options']);

        // A tenant enum: a configured label where there is one, a derived one otherwise.
        $this->assertSame([
            ['value' => 'consent', 'label' => 'Consent given'],
            ['value' => 'legal_obligation', 'label' => 'Legal obligation'],
        ], $fields['ndpr_lawful_basis']['options']);

        // A lookup: the tenant's own users, by id.
        $this->assertContains(['value' => $this->actor->id, 'label' => $this->actor->name], $fields['reviewer_id']['options']);
        $this->assertContains(['value' => $this->actor->id, 'label' => $this->actor->name], $fields['owner_id']['options']);

        // A scoring scale: the organisation's axis, not a hardcoded five.
        $profile = app(RiskScoringService::class)->profileFor();
        $scale = $fields['residual_likelihood']['options'];
        $this->assertCount(count($profile->axisLabels('likelihood')), $scale);
        $this->assertSame(1, $scale[0]['value']);
        $this->assertStringStartsWith('1 — ', $scale[0]['label']);

        // A user field with no explicit source still offers the users lookup.
        $this->assertContains(['value' => $this->actor->id, 'label' => $this->actor->name], $fields['approver']['options']);

        // A plain input has no options at all.
        $this->assertNull($fields['free_text']['options']);
    }

    /* ------------------------------------------------------------------ */
    /*  form(): values */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function values_follow_the_blade_renderers_rules_for_money_and_dates(): void
    {
        $this->addField(['code' => 'annual_licence_cost', 'label' => 'Annual Licence Cost', 'data_type' => 'money']);
        $this->addField(['code' => 'reviewed_on', 'label' => 'Reviewed On', 'data_type' => 'date']);
        $this->addField([
            'code' => 'last_test_date',
            'maps_to_column' => 'last_test_date',
            'label' => 'Last Tested',
            'data_type' => 'date',
        ]);
        $this->addField([
            'code' => 'created_at',
            'maps_to_column' => 'created_at',
            'label' => 'Created',
            'data_type' => 'datetime',
        ]);

        $control = $this->makeControl(['name' => 'Priced control', 'last_test_date' => '2026-03-01']);
        $this->storeAttributes($control, ['annual_licence_cost' => 250050, 'reviewed_on' => '2026-02-14']);

        $fields = $this->flatten($this->presenter->form('Control', $control));

        $this->assertSame('Priced control', $fields['name']['value']);
        // Stored in minor units, edited in major ones.
        $this->assertEquals(2500.5, $fields['annual_licence_cost']['value']);
        $this->assertSame('2026-02-14', $fields['reviewed_on']['value']);
        // A cast column arrives as Carbon and leaves as the input's format.
        $this->assertSame('2026-03-01', $fields['last_test_date']['value']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $fields['created_at']['value']);
    }

    #[Test]
    public function a_formula_field_is_present_but_read_only_and_never_posts(): void
    {
        $this->addField([
            'code' => 'computed_coverage',
            'label' => 'Computed Coverage',
            'data_type' => 'formula',
            'formula' => 'tested / total',
        ]);

        $control = $this->makeControl();
        $this->storeAttributes($control, ['computed_coverage' => 0.75]);

        $field = $this->flatten($this->presenter->form('Control', $control))['computed_coverage'];

        $this->assertTrue($field['readonly']);
        $this->assertSame('formula', $field['type']);
        $this->assertSame('tested / total', $field['formula']);
        $this->assertEquals(0.75, $field['value']);
        $this->assertNull($field['options']);
    }

    /* ------------------------------------------------------------------ */
    /*  form(): visibility */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_role_gated_field_is_absent_from_both_schemas_for_a_user_without_the_role(): void
    {
        Role::findOrCreate('data-protection-officer');

        $this->addField([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'string',
            'validation' => ['roles' => ['data-protection-officer']],
        ]);

        $control = $this->makeControl();
        $this->storeAttributes($control, ['ndpr_lawful_basis' => 'consent']);

        // A super-admin, but not a holder of the role the field names.
        $this->assertArrayNotHasKey('ndpr_lawful_basis', $this->flatten($this->presenter->form('Control', $control)));
        $this->assertArrayNotHasKey('ndpr_lawful_basis', $this->flattenDetail($this->presenter->detail($control, 'Control')));

        $this->actor->assignRole('data-protection-officer');
        $this->actingAs($this->actor->fresh());

        $this->assertArrayHasKey('ndpr_lawful_basis', $this->flatten($this->presenter->form('Control', $control)));
        $this->assertArrayHasKey('ndpr_lawful_basis', $this->flattenDetail($this->presenter->detail($control, 'Control')));
    }

    #[Test]
    public function visible_when_is_normalised_from_both_places_it_can_be_configured(): void
    {
        // The registry's own column-level rule on Issue.
        $issue = $this->flatten($this->presenter->form('Issue'));

        $this->assertSame(
            ['field' => 'issue_source', 'equals' => 'regulatory'],
            $issue['examination_ref']['visibleWhen']
        );
        $this->assertSame(
            ['field' => 'cbn_examination_finding', 'equals' => true],
            $issue['cbn_response_deadline']['visibleWhen']
        );
        $this->assertNull($issue['title']['visibleWhen']);

        // The WP-03 shape, under validation JSON with `attribute` for the key.
        $this->addField([
            'code' => 'third_party_name',
            'label' => 'Third Party',
            'data_type' => 'string',
            'validation' => ['visible_when' => ['attribute' => 'has_third_party', 'equals' => true]],
        ]);
        // And the column shape on a tenant field.
        $this->addField([
            'code' => 'breach_class',
            'label' => 'Breach Class',
            'data_type' => 'string',
            'visible_when' => ['field' => 'regulatory_reportable', 'equals' => '1'],
        ]);

        $control = $this->flatten($this->presenter->form('Control'));

        $this->assertSame(['field' => 'has_third_party', 'equals' => true], $control['third_party_name']['visibleWhen']);
        $this->assertSame(['field' => 'regulatory_reportable', 'equals' => '1'], $control['breach_class']['visibleWhen']);
    }

    /* ------------------------------------------------------------------ */
    /*  detail() */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function detail_displays_values_through_their_definitions(): void
    {
        $this->addField(['code' => 'annual_licence_cost', 'label' => 'Annual Licence Cost', 'data_type' => 'money']);
        $this->addField(['code' => 'is_outsourced', 'label' => 'Outsourced', 'data_type' => 'bool']);
        $this->addField(['code' => 'reviewer_email', 'label' => 'Reviewer Email', 'data_type' => 'string', 'is_pii' => true]);
        $this->addField(['code' => 'working_note', 'label' => 'Working Note', 'data_type' => 'text']);
        $this->addField([
            'code' => 'last_test_date',
            'maps_to_column' => 'last_test_date',
            'label' => 'Last Tested',
            'data_type' => 'date',
        ]);

        $control = $this->makeControl([
            'control_nature' => 'semi_automated',
            'last_test_date' => '2026-03-01',
        ]);
        $this->storeAttributes($control, [
            'annual_licence_cost' => 123400,
            'is_outsourced' => true,
            'working_note' => "Line one\nLine two",
        ]);

        $detail = $this->presenter->detail($control, 'Control');
        $fields = $this->flattenDetail($detail);

        foreach ($fields as $field) {
            $this->assertSame(['code', 'label', 'value', 'block', 'pii'], array_keys($field));
        }

        $this->assertSame('Semi-automated', $fields['control_nature']['value']);
        $this->assertSame('NGN 1,234.00', $fields['annual_licence_cost']['value']);
        $this->assertSame('Yes', $fields['is_outsourced']['value']);
        $this->assertSame('01 Mar 2026', $fields['last_test_date']['value']);
        $this->assertSame("Line one\nLine two", $fields['working_note']['value']);
        $this->assertTrue($fields['working_note']['block']);
        $this->assertTrue($fields['reviewer_email']['pii']);
        $this->assertNull($fields['reviewer_email']['value']);
        $this->assertFalse($fields['control_nature']['block']);
    }

    #[Test]
    public function detail_honours_omit_and_hide_empty(): void
    {
        $this->addField(['code' => 'reviewer_email', 'label' => 'Reviewer Email', 'data_type' => 'string']);

        $control = $this->makeControl(['name' => 'Named control']);

        $all = $this->flattenDetail($this->presenter->detail($control, 'Control'));
        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('reviewer_email', $all);

        $trimmed = $this->flattenDetail($this->presenter->detail($control, 'Control', omit: ['name'], hideEmpty: true));
        $this->assertArrayNotHasKey('name', $trimmed);
        $this->assertArrayNotHasKey('reviewer_email', $trimmed, 'an empty field goes when hideEmpty is set');
        $this->assertArrayHasKey('status', $trimmed, 'a populated field stays');
    }

    #[Test]
    public function detail_of_a_record_with_no_object_identity_is_empty_rather_than_an_error(): void
    {
        $this->assertSame(['sections' => []], $this->presenter->detail($this->actor));
    }

    /* ------------------------------------------------------------------ */
    /*  ValidatesConfiguredAttributes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_form_request_trait_builds_the_same_rule_set_the_persister_does(): void
    {
        Role::findOrCreate('data-protection-officer');

        $this->addField([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract'],
            'is_required' => true,
        ]);
        $this->addField([
            'code' => 'data_categories',
            'label' => 'Data Categories',
            'data_type' => 'multi_enum',
            'enum_options' => ['pii', 'financial'],
        ]);
        $this->addField([
            'code' => 'licence_cost',
            'label' => 'Licence Cost',
            'data_type' => 'money',
            'validation' => ['rules' => ['min:0']],
        ]);
        $this->addField(['code' => 'computed_coverage', 'label' => 'Computed', 'data_type' => 'formula', 'formula' => '1']);
        $this->addField([
            'code' => 'board_note',
            'label' => 'Board Note',
            'data_type' => 'text',
            'validation' => ['roles' => ['data-protection-officer']],
        ]);

        $request = new class
        {
            use ValidatesConfiguredAttributes;

            public function rules(string $type): array
            {
                return $this->configuredAttributeRules($type);
            }

            public function labels(string $type): array
            {
                return $this->configuredAttributeLabels($type);
            }
        };

        $rules = $request->rules('Control');

        $this->assertSame(['required', 'string', 'in:consent,contract'], $rules['configured_attributes.ndpr_lawful_basis']);
        $this->assertSame(['nullable', 'array'], $rules['configured_attributes.data_categories']);
        $this->assertSame(['string', 'in:pii,financial'], $rules['configured_attributes.data_categories.*']);
        $this->assertSame(['nullable', 'numeric', 'min:0'], $rules['configured_attributes.licence_cost']);

        // Mapped fields have the controller's own rule; computed and
        // role-hidden ones must not be validated into existence.
        $this->assertArrayNotHasKey('configured_attributes.name', $rules);
        $this->assertArrayNotHasKey('name', $rules);
        $this->assertArrayNotHasKey('configured_attributes.computed_coverage', $rules);
        $this->assertArrayNotHasKey('configured_attributes.board_note', $rules);

        $labels = $request->labels('Control');
        ksort($labels);

        $this->assertSame([
            'configured_attributes.data_categories' => 'Data Categories',
            'configured_attributes.licence_cost' => 'Licence Cost',
            'configured_attributes.ndpr_lawful_basis' => 'NDPR Lawful Basis',
        ], $labels);

        $this->assertSame([], $request->rules('NoSuchType'));
    }

    /* ------------------------------------------------------------------ */
    /*  ObjectVersion::create */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function persisting_configured_attributes_records_a_version_through_the_model(): void
    {
        $this->addField([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract'],
        ]);

        $this->actingAs($this->actor)->post(route('risk.controls.store'), [
            'name' => 'Versioned control',
            'description' => 'Description.',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'configured_attributes' => ['ndpr_lawful_basis' => 'consent'],
        ])->assertRedirect();

        $object = Control::where('name', 'Versioned control')->firstOrFail()->graphObject();

        $this->assertNotNull($object);

        $version = ObjectVersion::query()
            ->where('object_id', $object->id)
            ->where('change_reason', 'configured attributes saved with the record')
            ->first();

        $this->assertNotNull($version, 'PersistsConfiguredAttributes must record a version through the model');
        $this->assertSame($object->version, $version->version);
        $this->assertSame($this->actor->id, $version->changed_by);
        $this->assertSame('ui', $version->source);
        // The snapshot cast is `array`; a JSON string handed to create() would
        // have been encoded twice and come back as a string here.
        $this->assertIsArray($version->snapshot);
        $this->assertSame('consent', $version->snapshot['attributes']['ndpr_lawful_basis']);
    }

    /* ------------------------------------------------------------------ */

    private function addField(array $overrides): ObjectAttribute
    {
        return ObjectAttribute::create(array_merge([
            'object_type_id' => ObjectType::resolve('Control')->id,
            'section' => 'Details',
            'sort_order' => 500,
            'is_system' => false,
        ], $overrides));
    }

    private function addEveryBagType(): void
    {
        $order = 500;

        foreach ([
            ['code' => 'b_date', 'data_type' => 'date'],
            ['code' => 'b_money', 'data_type' => 'money'],
            ['code' => 'b_bool', 'data_type' => 'bool'],
            ['code' => 'b_decimal', 'data_type' => 'decimal'],
            ['code' => 'b_multi', 'data_type' => 'multi_enum', 'enum_options' => ['a', 'b']],
            ['code' => 'b_json', 'data_type' => 'json'],
            ['code' => 'b_datetime', 'data_type' => 'datetime'],
            ['code' => 'b_user', 'data_type' => 'user'],
            ['code' => 'b_ref', 'data_type' => 'object_ref'],
            ['code' => 'b_formula', 'data_type' => 'formula', 'formula' => '1 + 1'],
            [
                'code' => 'b_scale',
                'data_type' => 'int',
                'validation' => ['options_source' => 'scoring_scale', 'axis' => 'impact'],
            ],
        ] as $definition) {
            $this->addField(array_merge(['label' => ucfirst($definition['code']), 'sort_order' => $order += 10], $definition));
        }
    }

    /**
     * @return array<string, array<string, mixed>> code => field
     */
    private function flatten(array $schema): array
    {
        $out = [];

        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $out[$field['code']] = $field;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> code => field
     */
    private function flattenDetail(array $detail): array
    {
        return $this->flatten($detail);
    }

    private function storeAttributes(Model $record, array $values): void
    {
        $object = $record->graphObject();

        $this->assertNotNull($object, 'the fixture must have a graph identity to hang attributes off');

        $object->setCustomAttributes($values);
        $object->save();
    }
}
