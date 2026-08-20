<?php

namespace Tests\Feature\Metadata;

use App\Models\Control;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\Risk;
use App\Models\ScoringProfile;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * <x-dynamic-detail> on the two registers that carry it — the Risk register
 * and the Control library.
 *
 * The claim this defends is the product's core one: an administrator adds a
 * field in the metadata builder and it appears on screen without a developer.
 * <x-dynamic-form> already made that true of the write side. Until the detail
 * pages rendered the same metadata, a configured field could be captured and
 * then never read back — which is a worse outcome than never offering it.
 *
 * These are HTTP tests on purpose. The renderer was already exercised by
 * direct instantiation in DynamicRendererTest and still appeared on no page in
 * the application; only a real request through the route, the controller and
 * the layout proves it is wired.
 */
class DynamicDetailIntegrationTest extends TestCase
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
    /*  A configured field reaches the detail page */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_configured_attribute_appears_on_the_control_detail_page(): void
    {
        $this->addField('Control', [
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent', 'contract'],
        ]);

        $control = $this->makeControl(['name' => 'Customer onboarding lawful basis check']);
        $this->storeAttributes($control, ['ndpr_lawful_basis' => 'consent']);

        $response = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));

        $response->assertOk();
        $response->assertSee('Additional Information');
        $response->assertSee('NDPR Lawful Basis');
        // The label, not the stored code — the whole point of displaying a
        // value through its definition.
        $response->assertSee('Consent');
        $response->assertDontSee('consent', false);
    }

    #[Test]
    public function a_configured_attribute_appears_on_the_risk_detail_page(): void
    {
        $this->addField('Risk', [
            'code' => 'annual_transfer_cost',
            'label' => 'Annual Transfer Cost',
            'data_type' => 'money',
        ]);

        $risk = $this->makeRisk();
        // Money is stored in minor units.
        $this->storeAttributes($risk, ['annual_transfer_cost' => 250050]);

        $response = $this->actingAs($this->actor)->get(route('risk.register.show', $risk));

        $response->assertOk();
        $response->assertSee('Additional Information');
        $response->assertSee('Annual Transfer Cost');
        // Major units with a currency. The Attributes tab's editor holds the
        // same field but posts through Livewire and renders no formatted
        // value, so this string can only have come from the detail renderer.
        $response->assertSee('NGN 2,500.50');
    }

    /* ------------------------------------------------------------------ */
    /*  What the detail page must NOT show */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_attribute_marked_hidden_from_the_detail_view_is_not_rendered(): void
    {
        $this->addField('Control', [
            'code' => 'internal_working_note',
            'label' => 'Internal Working Note',
            'data_type' => 'string',
            'show_in_detail' => false,
        ]);

        $control = $this->makeControl();
        $this->storeAttributes($control, ['internal_working_note' => 'Chase the vendor before Friday']);

        $response = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));

        $response->assertOk();
        $response->assertDontSee('Internal Working Note');
        $response->assertDontSee('Chase the vendor before Friday');
    }

    #[Test]
    public function a_field_the_page_already_renders_by_hand_is_not_rendered_twice(): void
    {
        // business_unit_id is a column-backed Control attribute AND a row in
        // the hand-written Control Information panel. Without `omit` the page
        // would carry two "Business Unit" rows saying the same thing.
        //
        // The unit is populated deliberately: hideEmpty would drop a blank
        // field on its own and the test would pass for the wrong reason.
        $unit = \App\Models\BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $control = $this->makeControl(['business_unit_id' => $unit->id]);

        $response = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));

        $response->assertOk();

        $response->assertSee('Retail Banking');

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'Business Unit'),
            'the hand-written panel and the metadata renderer must not both draw the same field'
        );
    }

    #[Test]
    public function a_register_with_no_configured_attributes_shows_no_empty_panel(): void
    {
        // No tenant fields configured at all: the section — heading, border
        // and the component's own "nothing recorded" placeholder — must be
        // absent rather than present and empty.
        $control = $this->makeControl();
        $risk = $this->makeRisk();

        $controlPage = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));
        $controlPage->assertOk();
        $controlPage->assertDontSee('Additional Information');
        $controlPage->assertDontSee('Nothing recorded against the configured fields');

        $riskPage = $this->actingAs($this->actor)->get(route('risk.register.show', $risk));
        $riskPage->assertOk();
        $riskPage->assertDontSee('Additional Information');
        $riskPage->assertDontSee('Nothing recorded against the configured fields');
    }

    #[Test]
    public function a_configured_attribute_with_no_value_leaves_no_empty_panel_behind(): void
    {
        // Configured but never filled in. hideEmpty means the row goes, and
        // with the last row goes the whole section.
        $this->addField('Control', [
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent'],
        ]);

        $control = $this->makeControl();

        $response = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));

        $response->assertOk();
        $response->assertDontSee('Additional Information');
        $response->assertDontSee('NDPR Lawful Basis');
    }

    #[Test]
    public function an_attribute_the_user_may_not_see_is_absent_from_the_detail_page(): void
    {
        Role::findOrCreate('data-protection-officer');

        $this->addField('Control', [
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent'],
            'validation' => ['roles' => ['data-protection-officer']],
        ]);

        $control = $this->makeControl();
        $this->storeAttributes($control, ['ndpr_lawful_basis' => 'consent']);

        // The actor is a super-admin, which is not the same as holding the
        // role the field names. Read-side gating has to agree with the write
        // side or the field is hidden on the form and leaked on the page.
        $response = $this->actingAs($this->actor)->get(route('risk.controls.show', $control));

        $response->assertOk();
        $response->assertDontSee('NDPR Lawful Basis');
        $response->assertDontSee('Additional Information');
    }

    #[Test]
    public function a_holder_of_the_role_does_see_the_restricted_attribute(): void
    {
        Role::findOrCreate('data-protection-officer');
        $this->actor->assignRole('data-protection-officer');

        $this->addField('Control', [
            'code' => 'ndpr_lawful_basis',
            'label' => 'NDPR Lawful Basis',
            'data_type' => 'enum',
            'enum_options' => ['consent'],
            'validation' => ['roles' => ['data-protection-officer']],
        ]);

        $control = $this->makeControl();
        $this->storeAttributes($control, ['ndpr_lawful_basis' => 'consent']);

        $this->actingAs($this->actor)
            ->get(route('risk.controls.show', $control))
            ->assertOk()
            ->assertSee('NDPR Lawful Basis');
    }

    /* ------------------------------------------------------------------ */
    /*  Degradation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_record_with_no_object_identity_renders_nothing_rather_than_throwing(): void
    {
        // A User has no entry in the type registry, so the component cannot
        // resolve an ObjectType for it. Nothing to render is the right answer;
        // a fatal on a detail page is not.
        $component = new \App\View\Components\DynamicDetail(record: $this->actor);

        $this->assertNull($component->objectType);
        $this->assertTrue($component->fields->isEmpty());
        $this->assertTrue($component->sectioned()->isEmpty());

        $rendered = view('components.dynamic-detail', $component->data())->render();

        $this->assertStringContainsString('Nothing recorded', $rendered);
    }

    /* ------------------------------------------------------------------ */

    private function addField(string $typeCode, array $overrides = []): ObjectAttribute
    {
        return ObjectAttribute::create(array_merge([
            'object_type_id' => ObjectType::resolve($typeCode)->id,
            'section' => 'Details',
            'sort_order' => 500,
            'is_system' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeAttributes(Model $record, array $values): void
    {
        $object = $record->graphObject();

        $this->assertNotNull($object, 'the fixture must have a graph identity to hang attributes off');

        $object->setCustomAttributes($values);
        $object->save();
    }
}
