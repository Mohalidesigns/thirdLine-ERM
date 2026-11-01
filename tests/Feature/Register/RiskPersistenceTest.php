<?php

namespace Tests\Feature\Register;

use App\Models\Risk;
use PHPUnit\Framework\Attributes\Test;

/**
 * REGRESSION (E2E run 2026-10-18): RiskRegisterService writes risk_type, the
 * four inherent impact dimensions, review_frequency and appetite_notes, but
 * none of them was in Risk::$fillable, so Eloquent dropped every one without
 * a word. The create form's four required impacts were never stored, and the
 * edit form then opened with all four blank — required fields the user had
 * to fill again on every save, only for the save to drop them once more.
 */
class RiskPersistenceTest extends RegisterTestCase
{
    #[Test]
    public function storing_a_risk_keeps_every_field_the_form_sends(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload([
                'risk_type' => 'technology',
                'review_frequency' => 'quarterly',
                'notes' => 'Raised in the October IT steering committee.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $risk = Risk::where('title', 'Core banking outage')->firstOrFail();

        $this->assertSame('technology', $risk->risk_type);
        $this->assertEquals(4, $risk->inherent_impact_financial);
        $this->assertEquals(5, $risk->inherent_impact_operational);
        $this->assertEquals(3, $risk->inherent_impact_reputational);
        $this->assertEquals(2, $risk->inherent_impact_regulatory);
        $this->assertSame('quarterly', $risk->review_frequency);
        $this->assertSame('Raised in the October IT steering committee.', $risk->appetite_notes);
    }

    #[Test]
    public function updating_a_risk_keeps_the_impact_dimensions_and_review_fields(): void
    {
        $this->actingAs($this->actor)
            ->put(route('risk.register.update', $this->risk), $this->validPayload([
                'status' => 'active',
                'impact_financial' => 2,
                'impact_operational' => 3,
                'impact_reputational' => 4,
                'impact_regulatory' => 5,
                'review_frequency' => 'monthly',
                'notes' => 'Re-scored after the Q3 incident.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $risk = $this->risk->fresh();

        $this->assertEquals(2, $risk->inherent_impact_financial);
        $this->assertEquals(3, $risk->inherent_impact_operational);
        $this->assertEquals(4, $risk->inherent_impact_reputational);
        $this->assertEquals(5, $risk->inherent_impact_regulatory);
        $this->assertSame('monthly', $risk->review_frequency);
        $this->assertSame('Re-scored after the Q3 incident.', $risk->appetite_notes);
    }

    #[Test]
    public function a_review_frequency_outside_the_offered_cycles_is_a_validation_error(): void
    {
        // Now that the column is written, the old `max:50` rule let a 21-50
        // character value through to a varchar(20) — a 500 on a strict-mode
        // database rather than a message on the field.
        foreach (['fortnightly', str_repeat('q', 21)] as $value) {
            $this->actingAs($this->actor)
                ->post(route('risk.register.store'), $this->validPayload(['review_frequency' => $value]))
                ->assertSessionHasErrors('review_frequency');

            $this->actingAs($this->actor)
                ->put(route('risk.register.update', $this->risk), $this->validPayload([
                    'status' => 'active',
                    'review_frequency' => $value,
                ]))
                ->assertSessionHasErrors('review_frequency');
        }

        $this->assertSame(0, Risk::where('title', 'Core banking outage')->count());
    }

    #[Test]
    public function the_edit_form_is_prefilled_with_the_stored_impact_dimensions(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload(['review_frequency' => 'annual']));

        $risk = Risk::where('title', 'Core banking outage')->firstOrFail();

        $this->actingAs($this->actor)
            ->get(route('risk.register.edit', $risk))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('risk.impact_financial', 4)
                ->where('risk.impact_operational', 5)
                ->where('risk.impact_reputational', 3)
                ->where('risk.impact_regulatory', 2)
                ->where('risk.review_frequency', 'annual')
                ->etc());
    }
}
