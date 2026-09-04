<?php

namespace Tests\Feature\Register;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.2: the register's Form Requests.
 *
 * The point of every case below is one rule — a foreign key is checked INSIDE
 * THE TENANT. A bare `exists:users,id` accepts any user in the database, so a
 * caller could make a user from another bank the owner of one of their risks,
 * and that user's name would then render on the page.
 */
class RiskRequestsTest extends RegisterTestCase
{
    #[Test]
    public function no_form_request_uses_the_string_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Register/*.php')) as $file) {
            $this->assertStringNotContainsString(
                "'exists:",
                (string) file_get_contents($file),
                basename($file).' uses a string exists rule, which is not tenant-scoped',
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function foreignKeys(): array
    {
        return [
            'category' => ['category_id'],
            'business unit' => ['business_unit_id'],
            'risk owner' => ['risk_owner_id'],
            'risk steward' => ['risk_steward_id'],
            'identified by' => ['identified_by'],
        ];
    }

    #[Test]
    #[DataProvider('foreignKeys')]
    public function storing_rejects_a_foreign_id_from_another_organisation(string $field): void
    {
        $foreign = match ($field) {
            'category_id' => $this->foreignCategory->id,
            'business_unit_id' => $this->foreignUnit->id,
            default => $this->otherActor->id,
        };

        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload([$field => $foreign]))
            ->assertSessionHasErrors($field);
    }

    #[Test]
    #[DataProvider('foreignKeys')]
    public function updating_rejects_a_foreign_id_from_another_organisation(string $field): void
    {
        $foreign = match ($field) {
            'category_id' => $this->foreignCategory->id,
            'business_unit_id' => $this->foreignUnit->id,
            default => $this->otherActor->id,
        };

        $this->actingAs($this->actor)
            ->put(route('risk.register.update', $this->risk), $this->validPayload([
                'status' => 'active',
                $field => $foreign,
            ]))
            ->assertSessionHasErrors($field);
    }

    #[Test]
    public function mapping_rejects_a_control_from_another_organisation(): void
    {
        $foreignControl = \App\Support\Tenancy\TenantContext::bypass(
            fn () => \App\Models\Control::create([
                'organization_id' => $this->otherOrg->id,
                'control_code' => 'CTL-FOREIGN',
                'name' => 'Theirs',
                'status' => 'active',
                'created_by' => $this->otherActor->id,
            ]),
            'test fixture',
        );

        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $foreignControl->id])
            ->assertSessionHasErrors('control_id');
    }

    #[Test]
    public function storing_requires_the_fields_the_screen_marks_required(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), [])
            ->assertSessionHasErrors([
                'title', 'description', 'category_id', 'business_unit_id', 'risk_owner_id',
                'inherent_likelihood', 'impact_financial', 'impact_operational',
                'impact_reputational', 'impact_regulatory',
            ]);
    }

    #[Test]
    public function updating_requires_a_status_but_storing_does_not(): void
    {
        // The two rule sets differed exactly here, and still do: a saved risk
        // always has a status, a new one defaults to active.
        $this->actingAs($this->actor)
            ->put(route('risk.register.update', $this->risk), $this->validPayload())
            ->assertSessionHasErrors('status');

        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload())
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function scores_outside_the_matrix_are_rejected(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload([
                'inherent_likelihood' => 6,
                'impact_financial' => 0,
            ]))
            ->assertSessionHasErrors(['inherent_likelihood', 'impact_financial']);
    }

    #[Test]
    public function the_create_route_refuses_a_caller_without_the_permission(): void
    {
        $viewer = $this->userWith(['risk.view'], 'viewer-req@example.test');

        $this->actingAs($viewer)->get(route('risk.register.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('risk.register.store'), $this->validPayload())->assertForbidden();
    }
}
