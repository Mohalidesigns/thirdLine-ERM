<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scenario;
use App\Services\Sm\ReferenceGenerator;
use App\Services\Sm\ScenarioService;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `SM-{YYYY}-{NNN}` reference (addendum-2, spec 02 §5.1, spec 04 §4).
 *
 * THE TWO PRECEDENT DEFECTS THIS SUITE PINS. Both existing generators
 * (`Quantification\ScenarioService::nextReference()` and `LossEventController`'s near-miss
 * one) do two things that are wrong here, and each has a test:
 *
 *   1. `now()->year` — `config/app.php` is UTC, so between 23:00 and 24:00 UTC on
 *      31 December (00:00–01:00 on 1 January in Lagos) they stamp the OLD year onto a
 *      reference the user is watching being created on New Year's Day.
 *   2. `orderByDesc(reference)` + `(int) substr($ref, -3)` — correct only while the
 *      sequence is ≤999. `SM-2026-1000` sorts BEFORE `SM-2026-999` as a string, and
 *      `substr(-3)` of it is `000`, so the 1000th scenario is followed by a second
 *      `SM-2026-001` which the unique key then rejects FOREVER, with no path forward.
 */
class ScenarioReferenceTest extends SmTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_first_reference_of_a_year_is_sm_year_001(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->assertSame('SM-2026-001', app(ReferenceGenerator::class)->next($this->organization->id));

        $response = $this->actingAs($this->actor)
            ->post(route('scenario.store'), ['name' => 'Loss of key staff']);

        $created = Scenario::query()->sole();

        $this->assertSame('SM-2026-001', $created->reference);
        // Spec 04 §6.1: the flash speaks the reference for the FIRST time, and it is the
        // server's string, not the client's.
        $this->assertSame('SM-2026-001 created.', session('success'));
        // And the redirect lands on the workspace at ?tab=overview, where the real reference
        // is in the header — the user sees the number once, when it is true.
        $response->assertRedirect(route('scenario.show', ['scenario' => $created, 'tab' => 'overview']));
    }

    #[Test]
    public function the_sequence_increments_per_tenant_and_not_globally(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $generator = app(ReferenceGenerator::class);

        $this->makeScenario(['reference' => 'SM-2026-001']);
        $this->makeScenario(['reference' => 'SM-2026-002']);

        $this->assertSame('SM-2026-003', $generator->next($this->organization->id));

        // The other tenant's SM-2026-901 (from the shared fixture) must not move this one:
        // `reference` is unique PER TENANT, following bcms_management_reviews rather than
        // loss_events' global unique, which is "a defect waiting for a second tenant". So the
        // other tenant continues its OWN sequence at 902 while this one is at 003.
        $this->assertSame('SM-2026-003', $generator->next($this->organization->id));
        $this->assertSame('SM-2026-902', $generator->next($this->otherOrg->id));
    }

    #[Test]
    public function the_year_is_taken_from_lagos_and_not_from_utc(): void
    {
        // DEFECT 1. 23:30 UTC on 31 December is 00:30 on 1 January in Lagos. A user creating
        // a scenario on New Year's Day must get the New Year's reference, which `now()->year`
        // would not give them — and addendum-2 names `KriWorkingCalendar::today()` as the fix,
        // reusing the helper's TIMEZONE const rather than adding a fourth spelling of
        // 'Africa/Lagos' to the codebase.
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        $this->assertSame(2026, (int) now()->format('Y'), 'the application clock is UTC, which is the trap');
        $this->assertSame(2027, KriWorkingCalendar::today()->year);

        $this->assertSame('SM-2027-001', app(ReferenceGenerator::class)->next($this->organization->id));
    }

    #[Test]
    public function an_earlier_year_does_not_disturb_the_current_sequence(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 09:00:00', 'UTC'));

        $this->makeScenario(['reference' => 'SM-2026-042']);

        $this->assertSame('SM-2027-001', app(ReferenceGenerator::class)->next($this->organization->id));
    }

    #[Test]
    public function the_sequence_survives_the_nine_hundred_and_ninety_ninth_scenario(): void
    {
        // DEFECT 2, the one with no path forward. `orderByDesc` of the STRING puts
        // SM-2026-999 above SM-2026-1000, and `substr(-3)` of the winner is `999`, so the
        // next reference is a second SM-2026-1000 — rejected by the unique key forever.
        // `substr($r, 8)` over a plucked set has no such ceiling.
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->makeScenario(['reference' => 'SM-2026-999']);
        $this->makeScenario(['reference' => 'SM-2026-1000']);

        $generator = app(ReferenceGenerator::class);

        $this->assertSame('SM-2026-1001', $generator->next($this->organization->id));
        $this->assertSame(1001, $generator->nextNumber($this->organization->id, 2026));

        // And the width is not capped at three: %03d pads but never truncates.
        $this->assertSame('SM-2026-1001', sprintf('SM-2026-%03d', 1001));
    }

    #[Test]
    public function a_soft_deleted_scenario_still_occupies_its_reference(): void
    {
        // The unique index does not care about `deleted_at`, so the sequence must not either.
        // Excluding soft-deleted rows would hand out a reference the index then rejects —
        // forever, for that tenant and year, with no path forward.
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $deleted = $this->makeScenario(['reference' => 'SM-2026-007']);
        $deleted->delete();

        $this->assertSoftDeleted('sm_scenarios', ['id' => $deleted->id]);
        $this->assertSame('SM-2026-008', app(ReferenceGenerator::class)->next($this->organization->id));
    }

    #[Test]
    public function a_reference_collision_is_retried_and_the_user_sees_the_first_reference_that_exists(): void
    {
        // Addendum-2's collision rule. The two precedents have none: they read-then-insert with
        // no lock and no retry, so two concurrent creates both get the same number and one 500s.
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->makeScenario(['reference' => 'SM-2026-001']);

        $generator = Mockery::mock(ReferenceGenerator::class);
        // Two collisions, then the sequence re-read after the winners committed.
        $generator->shouldReceive('next')->withAnyArgs()
            ->andReturn('SM-2026-001', 'SM-2026-001', 'SM-2026-004')
            ->times(3);
        $this->app->instance(ReferenceGenerator::class, $generator);

        $this->actingAs($this->actor)
            ->post(route('scenario.store'), ['name' => 'Concurrent scenario'])
            ->assertSessionHasNoErrors();

        $this->assertSame('SM-2026-004', Scenario::query()->where('name', 'Concurrent scenario')->sole()->reference);
    }

    #[Test]
    public function a_reference_that_cannot_be_assigned_after_three_attempts_flashes_and_never_500s(): void
    {
        // "at most 3 attempts; after that, a flash message … never a 500, and never a silently
        // different reference."
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->makeScenario(['reference' => 'SM-2026-001']);

        $generator = Mockery::mock(ReferenceGenerator::class);
        $generator->shouldReceive('next')->withAnyArgs()->andReturn('SM-2026-001')->times(ScenarioService::MAX_REFERENCE_ATTEMPTS);
        $this->app->instance(ReferenceGenerator::class, $generator);

        $this->actingAs($this->actor)
            ->post(route('scenario.store'), ['name' => 'Doomed scenario'])
            ->assertSessionHas('error', ScenarioService::REFERENCE_UNAVAILABLE_MESSAGE)
            ->assertSessionHasNoErrors();

        $this->assertSame('We could not assign a reference. Please try again.', ScenarioService::REFERENCE_UNAVAILABLE_MESSAGE);
        $this->assertDatabaseMissing('sm_scenarios', ['name' => 'Doomed scenario']);
        // Three attempts and no more: a retry loop with no bound is a denial of service the
        // application inflicts on itself.
        $this->assertSame(1, Scenario::query()->count());
    }

    #[Test]
    public function a_posted_reference_is_refused_because_it_can_only_be_a_guess(): void
    {
        // Spec 04 §4: the sequence is read at write time, so any number shown before the write
        // is a guess — two designers creating at once are both shown the same provisional
        // reference and one of them is saved with a different one. The screen would have
        // displayed a reference that never existed, which is standard §5's defect class in the
        // one field a user is most likely to copy into an email before saving.
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->actingAs($this->actor)
            ->post(route('scenario.store'), ['name' => 'Smuggled reference', 'reference' => 'SM-2026-777'])
            ->assertSessionHasErrors(['reference' => 'The reference is assigned when you save.']);

        $this->assertDatabaseMissing('sm_scenarios', ['name' => 'Smuggled reference']);
    }

    #[Test]
    public function the_create_page_sends_no_provisional_reference(): void
    {
        // The other half of the same rule: the payload has no `reference` key at all, so there
        // is nothing for the JSX to render. Spec 04 §4: "No number. No placeholder shaped like
        // a number. No SM-2026-015."
        $this->actingAs($this->actor)
            ->get(route('scenario.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sm/Scenarios/Create')
                ->where('values', [
                    'name' => '',
                    'business_unit_id' => null,
                    // NOT NULL default 'local' (§A1.2), so the form opens on a real value
                    // rather than a "Choose…" the user has to clear — and `local` is the
                    // narrowest claim available.
                    'nature' => 'local',
                    'risk_taxonomy_node_id' => null,
                ])
                ->has('urls.store')
                ->has('urls.cancel')
                // Spec 04 §2: no weightings, currencies, fxRates or risk-search URL — none of
                // those fields is on this form and their option lists would be dead bytes on
                // the slowest link in the product.
                ->missing('weightings')
                ->missing('currencies')
                ->missing('fxRates')
                ->missing('reference')
                ->missing('values.reference'));
    }
}
