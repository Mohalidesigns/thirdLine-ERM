<?php

namespace Tests\Feature\Metadata;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\Risk;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-03 TASK 7 — a custom attribute added via object_attributes renders,
 * validates and persists. No migration, no model change, no deploy.
 *
 * MIGRATION PHASE 6.8. This is the same acceptance criterion that
 * tests/Feature/Graph/DynamicFormTest.php pinned against the Livewire
 * DynamicForm component, moved onto the HTTP path that replaced it:
 *
 *     GET   risk/register/{register}/edit   the schema the form OFFERS
 *     PATCH risk/register/{register}/attributes  what the server ACCEPTS
 *
 * It is not a translation exercise. The Livewire component owned its own
 * rendering, its own validation and its own persistence, and the Inertia
 * replacement splits those across FormSchemaPresenter,
 * ValidatesConfiguredAttributes and PersistsConfiguredAttributes — three
 * places that have to agree. Every assertion below is about the agreement,
 * which is why the offered-schema half and the accepted-value half sit in one
 * file: a field the form offers and the validator refuses, or one the
 * validator accepts and the form never offered, is the defect this phase kept
 * finding.
 *
 * ONE RULE WAS NOT PORTED WHEN THE SCREENS WERE — it was written for this
 * phase. `is_unique` lived only inside the Livewire component's private
 * enforceUniqueness(); ObjectAttribute::validationRules(), which is what every
 * Inertia screen has used since Phase 3.2, has never produced a uniqueness
 * rule. See App\Rules\UniqueConfiguredAttribute.
 */
class ConfiguredAttributeWriteTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private ObjectType $riskType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['risk.view', 'risk.edit'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->riskType = ObjectType::resolve('Risk');
        $this->actingAs($this->actor);
    }

    /* ------------------------------------------------------------------ */
    /*  What the form offers */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_configured_attribute_is_offered_on_the_edit_form(): void
    {
        $this->addAttribute([
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR lawful basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract', 'legal_obligation', 'legitimate_interest'],
            'help_text' => 'The basis relied on for processing personal data.',
        ]);

        $risk = $this->makeRisk();

        $field = $this->offeredField($risk, 'ndpr_lawful_basis');

        $this->assertNotNull($field, 'The configured attribute must reach the edit form.');
        $this->assertSame('NDPR lawful basis', $field['label']);
        $this->assertSame('The basis relied on for processing personal data.', $field['help']);
        $this->assertContains('legitimate_interest', array_column($field['options'], 'value'));
        $this->assertSame('configured_attributes[ndpr_lawful_basis]', $field['name']);
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

        // The Blade form dropped formula fields, having nowhere to put an input
        // that must not post; the React form shows them as a read-only box. The
        // rule that matters is unchanged and is asserted below: displayed, and
        // never accepted.
        $field = $this->offeredField($risk, 'exposure_ratio');

        $this->assertNotNull($field);
        $this->assertTrue($field['readonly'], 'A computed attribute must not be editable.');
        $this->assertSame('residual_score / inherent_score', $field['formula']);

        // Posting it anyway writes nothing. It is DROPPED rather than refused:
        // a formula attribute is filtered out before any rule is built for it,
        // so there is no rule to fail and no error to report. (validationRules()
        // does say `prohibited` for the type, which is the belt to this
        // filter's braces — it fires only if some other caller builds rules
        // without filtering first.)
        //
        // Silently ignoring input is normally the failure this suite hunts. It
        // is right here for the one reason that makes it right anywhere: the
        // field is read-only on the form, so the only way to post a value is to
        // fabricate the request, and a fabricated value for a derived field has
        // no honest error message to receive.
        $this->save($risk, ['exposure_ratio' => '999'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->stored($risk, 'exposure_ratio'));
    }

    #[Test]
    public function a_type_with_no_configured_attributes_offers_no_sections(): void
    {
        $risk = $this->makeRisk();

        $response = $this->get(route('risk.register.edit', $risk));
        $response->assertOk();

        $this->assertSame([], $this->schemaOf($response)['sections']);
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

    /* ------------------------------------------------------------------ */
    /*  What the server accepts */
    /* ------------------------------------------------------------------ */

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

        $this->save($risk, ['regulator_reference' => ''])
            ->assertSessionHasErrors('configured_attributes.regulator_reference');
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

        $this->save($risk, ['ndpr_lawful_basis' => 'whatever-i-like'])
            ->assertSessionHasErrors('configured_attributes.ndpr_lawful_basis');

        $this->assertNull($this->stored($risk, 'ndpr_lawful_basis'));
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

        $this->save($risk, ['ndpr_lawful_basis' => 'contract'])
            ->assertSessionHasNoErrors();

        $this->assertSame('contract', $this->stored($risk, 'ndpr_lawful_basis'));
    }

    #[Test]
    public function saving_attributes_records_a_version(): void
    {
        $this->addAttribute(['code' => 'note', 'label' => 'Note', 'data_type' => 'text']);

        $risk = $this->makeRisk();
        $before = (int) $risk->graphObject()->version;

        $this->save($risk, ['note' => 'Reviewed with the CRO'])
            ->assertSessionHasNoErrors();

        $object = $risk->graphObject()->refresh();

        $this->assertSame($before + 1, (int) $object->version);
        $this->assertDatabaseHas('object_versions', [
            'object_id' => $object->id,
            'version' => $object->version,
            'change_reason' => 'configured attributes saved with the record',
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

        $this->save($risk, ['insured_limit' => '2500.50'])
            ->assertSessionHasNoErrors();

        // Platform rule: money is stored in minor units.
        $this->assertSame(250050, $this->stored($risk, 'insured_limit'));
    }

    /* ------------------------------------------------------------------ */
    /*  Who may set what */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_role_restricted_attribute_is_neither_offered_nor_accepted(): void
    {
        // The role only has to EXIST and be assignable — Gate::before gives a
        // super-admin every ability, and what is under test is whether the
        // attribute's `validation.roles` gate is consulted at all. Running the
        // full seeder here would collide with the permissions setUp created.
        Role::findOrCreate('super-admin');

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
        $ordinary->givePermissionTo(['risk.view', 'risk.edit']);

        $this->actingAs($ordinary);

        // Not merely hidden with CSS: never offered, and never accepted.
        $this->assertNull($this->offeredField($risk, 'board_commentary'));

        $this->save($risk, ['board_commentary' => 'Smuggled in from devtools']);

        $this->assertNull(
            $this->stored($risk, 'board_commentary'),
            'A field the user may not see must not be writable by posting its name.'
        );

        $privileged = User::create([
            'name' => 'Admin user',
            'email' => 'admin-attr@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $privileged->givePermissionTo(['risk.view', 'risk.edit']);
        $privileged->assignRole('super-admin');

        $this->actingAs($privileged);

        $this->assertNotNull($this->offeredField($risk, 'board_commentary'));
    }

    /* ------------------------------------------------------------------ */
    /*  Uniqueness */
    /* ------------------------------------------------------------------ */

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

        $this->save($first, ['regulator_reference' => 'CBN/2026/0001'])
            ->assertSessionHasNoErrors();

        $this->save($second, ['regulator_reference' => 'CBN/2026/0001'])
            ->assertSessionHasErrors('configured_attributes.regulator_reference');

        $this->assertNull($this->stored($second, 'regulator_reference'));
    }

    #[Test]
    public function a_unique_attribute_does_not_collide_with_the_record_being_edited(): void
    {
        $this->addAttribute([
            'code' => 'regulator_reference',
            'label' => 'Regulator reference',
            'data_type' => 'string',
            'is_unique' => true,
        ]);

        $risk = $this->makeRisk();

        $this->save($risk, ['regulator_reference' => 'CBN/2026/0001'])
            ->assertSessionHasNoErrors();

        // Re-saving the record without changing the field must not report the
        // record's own value as a duplicate of itself.
        $this->save($risk, ['regulator_reference' => 'CBN/2026/0001'])
            ->assertSessionHasNoErrors();

        $this->assertSame('CBN/2026/0001', $this->stored($risk, 'regulator_reference'));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(Risk $risk, array $values): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('risk.register.edit', $risk))
            ->patch(route('risk.register.attributes', $risk), [
                'configured_attributes' => $values,
            ]);
    }

    private function stored(Risk $risk, string $code): mixed
    {
        return $risk->graphObject()->refresh()->customAttributes()[$code] ?? null;
    }

    /**
     * The field as the edit form offers it, or null when the form does not
     * offer it at all.
     *
     * @return array<string, mixed>|null
     */
    private function offeredField(Risk $risk, string $code): ?array
    {
        $response = $this->get(route('risk.register.edit', $risk));
        $response->assertOk();

        foreach ($this->schemaOf($response)['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['code'] ?? null) === $code) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * @return array{objectType: mixed, sections: array<int, array<string, mixed>>}
     */
    private function schemaOf(\Illuminate\Testing\TestResponse $response): array
    {
        return $response->viewData('page')['props']['schema'];
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
