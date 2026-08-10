<?php

namespace Tests\Feature\Graph;

use App\Livewire\DynamicForm;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-03 TASK 7 — a custom attribute added via object_attributes renders,
 * validates and persists.
 *
 * No migration, no model change, no deploy: the acceptance criterion in full.
 */
class DynamicFormTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private ObjectType $riskType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->riskType = ObjectType::resolve('Risk');
        $this->actingAs($this->actor);
    }

    #[Test]
    public function a_configured_attribute_renders_on_the_form(): void
    {
        $this->addAttribute([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR lawful basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract', 'legal_obligation', 'legitimate_interest'],
            'help_text' => 'The basis relied on for processing personal data.',
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->assertSee('NDPR lawful basis')
            ->assertSee('legitimate_interest')
            ->assertSee('The basis relied on for processing personal data.');
    }

    #[Test]
    public function a_required_attribute_is_validated_server_side(): void
    {
        $this->addAttribute([
            'code' => 'regulator_reference',
            'label' => 'Regulator reference',
            'data_type' => 'string',
            'is_required' => true,
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->set('values.regulator_reference', '')
            ->call('save')
            ->assertHasErrors(['values.regulator_reference' => 'required']);
    }

    #[Test]
    public function an_enum_rejects_a_value_outside_its_options(): void
    {
        $this->addAttribute([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR lawful basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract'],
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->set('values.ndpr_lawful_basis', 'whatever-i-like')
            ->call('save')
            ->assertHasErrors('values.ndpr_lawful_basis');
    }

    #[Test]
    public function a_valid_value_persists_into_the_objects_attribute_bag(): void
    {
        $this->addAttribute([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR lawful basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract'],
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->set('values.ndpr_lawful_basis', 'contract')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('contract', $risk->graphObject()->refresh()->customAttribute('ndpr_lawful_basis'));
    }

    #[Test]
    public function saving_attributes_records_a_version(): void
    {
        $this->addAttribute(['code' => 'note', 'label' => 'Note', 'data_type' => 'text']);

        $risk = $this->makeRisk();
        $before = $risk->graphObject()->version;

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->set('values.note', 'Reviewed with the CRO')
            ->call('save');

        $object = $risk->graphObject()->refresh();

        $this->assertSame($before + 1, $object->version);
        $this->assertDatabaseHas('object_versions', [
            'object_id' => $object->id,
            'version' => $object->version,
            'change_reason' => 'configured attributes updated',
        ]);
    }

    #[Test]
    public function money_is_typed_in_naira_and_stored_in_kobo(): void
    {
        $this->addAttribute([
            'code' => 'insured_limit',
            'label' => 'Insured limit',
            'data_type' => 'money',
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->set('values.insured_limit', '2500.50')
            ->call('save')
            ->assertHasNoErrors();

        // Platform rule: money is stored in minor units.
        $this->assertSame(250050, $risk->graphObject()->refresh()->customAttribute('insured_limit'));
    }

    #[Test]
    public function a_formula_attribute_is_displayed_but_never_accepted(): void
    {
        $this->addAttribute([
            'code' => 'exposure_ratio',
            'label' => 'Exposure ratio',
            'data_type' => 'formula',
            'formula' => 'residual_score / inherent_score',
        ]);

        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->assertSee('residual_score / inherent_score')
            ->set('values.exposure_ratio', '999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(
            $risk->graphObject()->refresh()->customAttribute('exposure_ratio'),
            'A computed attribute must not be writable from the form'
        );
    }

    #[Test]
    public function a_role_restricted_attribute_is_absent_for_users_without_the_role(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->addAttribute([
            'code' => 'board_commentary',
            'label' => 'Board commentary',
            'data_type' => 'text',
            'validation' => ['roles' => ['super-admin']],
        ]);

        $risk = $this->makeRisk();

        $ordinary = User::create([
            'name' => 'Ordinary user',
            'email' => 'ordinary@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($ordinary);

        // Not merely hidden with CSS: never rendered, and never accepted.
        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->assertDontSee('Board commentary')
            ->set('values.board_commentary', 'Smuggled in from devtools')
            ->call('save');

        $this->assertNull($risk->graphObject()->refresh()->customAttribute('board_commentary'));

        $privileged = User::create([
            'name' => 'Admin user',
            'email' => 'admin-attr@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $privileged->assignRole('super-admin');

        $this->actingAs($privileged);

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->assertSee('Board commentary');
    }

    #[Test]
    public function an_inherited_attribute_appears_on_the_child_type(): void
    {
        $this->addAttribute(['code' => 'note', 'label' => 'Inherited note', 'data_type' => 'string']);

        // Opportunity inherits from Risk in the seeded registry.
        $opportunity = ObjectType::resolve('Opportunity');
        $this->assertSame($this->riskType->id, $opportunity->parent_type_id);

        $this->assertTrue(
            $opportunity->resolvedAttributes()->has('note'),
            'Opportunity must inherit the attributes configured on Risk'
        );
    }

    #[Test]
    public function a_unique_attribute_refuses_a_duplicate_value(): void
    {
        $this->addAttribute([
            'code' => 'regulator_reference',
            'label' => 'Regulator reference',
            'data_type' => 'string',
            'is_unique' => true,
        ]);

        $first = $this->makeRisk();
        $second = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $first])
            ->set('values.regulator_reference', 'CBN/2026/0001')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $second])
            ->set('values.regulator_reference', 'CBN/2026/0001')
            ->call('save')
            ->assertHasErrors('values.regulator_reference');
    }

    #[Test]
    public function a_type_with_no_configured_attributes_renders_an_explanation_rather_than_an_empty_box(): void
    {
        $risk = $this->makeRisk();

        Livewire::test(DynamicForm::class, ['objectType' => 'Risk', 'model' => $risk])
            ->assertSee('No configured attributes on this object type yet.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addAttribute(array $attributes): ObjectAttribute
    {
        return ObjectAttribute::create(array_merge([
            'object_type_id' => $this->riskType->id,
            'section' => 'Regulatory',
            'sort_order' => 10,
        ], $attributes));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }
}
