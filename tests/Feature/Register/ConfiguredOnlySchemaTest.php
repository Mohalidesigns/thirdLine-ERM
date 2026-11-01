<?php

namespace Tests\Feature\Register;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Presenters\FormSchemaPresenter;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

/**
 * The three private configuredOnly() copies moved to FormSchemaPresenter. Each
 * screen that used one must still hand React only the tenant-configured fields.
 */
class ConfiguredOnlySchemaTest extends RegisterTestCase
{
    private function assertNoMapped(array $schema, string $where): void
    {
        foreach ($schema['sections'] as $section) {
            $this->assertNotSame([], $section['fields'], "{$where}: empty section {$section['code']} kept");
            foreach ($section['fields'] as $field) {
                $this->assertFalse($field['mapped'], "{$where}: {$field['code']} is column-backed");
            }
        }
    }

    /** Risk has no registry-seeded column-backed fields, so give it one of each kind. */
    private function seedRiskFields(): void
    {
        $type = ObjectType::resolve('Risk');
        foreach ([['mapped_title', 'title', true], ['regulatory_driver', null, false]] as [$code, $column, $_]) {
            ObjectAttribute::create([
                'object_type_id' => $type->id, 'code' => $code, 'label' => $code, 'data_type' => 'string',
                'section' => 'Details', 'sort_order' => 900, 'is_system' => false, 'maps_to_column' => $column,
            ]);
        }
    }

    #[Test]
    public function the_presenter_drops_mapped_fields_and_empty_sections_only(): void
    {
        $this->actingAs($this->actor);
        $this->seedRiskFields();
        $presenter = app(FormSchemaPresenter::class);

        foreach (['Risk', 'Control', 'KeyRiskIndicator'] as $type) {
            $full = $presenter->form($type);
            $mappedBefore = collect($full['sections'])->flatMap(fn ($s) => $s['fields'])->where('mapped', true)->count();
            $configuredBefore = collect($full['sections'])->flatMap(fn ($s) => $s['fields'])->where('mapped', false)->count();

            $only = $presenter->configuredOnly($full);

            $this->assertGreaterThan(0, $mappedBefore, "{$type}: nothing mapped, the filter test is vacuous");
            $this->assertNoMapped($only, $type);
            $this->assertSame($configuredBefore, collect($only['sections'])->flatMap(fn ($s) => $s['fields'])->count(), "{$type}: configured fields lost");
            $this->assertSame($full['objectType'], $only['objectType']);
        }
    }

    #[Test]
    public function register_and_control_screens_still_get_the_configured_only_schema(): void
    {
        foreach (['control.view', 'control.create', 'control.edit'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }
        $this->actingAs($this->actor);
        $this->seedRiskFields();
        $control = $this->control;

        foreach ([
            ['risk.register.create', [], 'schema'],
            ['risk.register.edit', [$this->risk], 'schema'],
            ['risk.register.show', [$this->risk], 'configured'],
            ['risk.controls.create', [], 'schema'],
            ['risk.controls.edit', [$control], 'schema'],
        ] as [$name, $params, $prop]) {
            $props = $this->get(route($name, $params))->assertOk()->viewData('page')['props'];
            $this->assertNoMapped($props[$prop], $name);
            if (str_starts_with($name, 'risk.register')) {
                $codes = collect($props[$prop]['sections'])->flatMap(fn ($s) => collect($s['fields'])->pluck('code'));
                $this->assertContains('regulatory_driver', $codes->all(), "{$name}: configured field lost");
            }
        }
    }
}
