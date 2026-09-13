<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ContactSource;
use App\Exceptions\Bcms\CircularAudienceRuleException;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Contact;
use App\Models\Bcms\SavedGroup;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\AudienceResolver;
use App\Services\Bcms\Emns\AlertService;
use App\Support\Bcms\AudienceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `AudienceResolver::bySavedGroup()` — Gate 1 Set A defect 1.
 *
 * Before this fix, a saved group that (directly or through a chain) referred
 * back to itself made `resolveIds()` recurse forever: `AudienceRule::MAX_DEPTH`
 * bounds nesting INSIDE one stored rule document, but each `saved_group` hop
 * starts a fresh `AudienceRule::fromArray()` at depth 0, so it could not see
 * across the hop. The fix refuses the whole resolution with a named exception
 * rather than silently truncating — a wrong count that looks right is worse
 * than an error on the console that shows the live recipient count.
 */
class AudienceResolverCycleGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * MUTATION: without the `in_array($groupId, $visitedGroupIds, true)` guard
     * in `bySavedGroup()`, this recurses forever (a hard hang / OOM) rather
     * than raising. Reverting the guard reproduces the reported symptom.
     */
    #[Test]
    public function a_group_that_refers_to_itself_is_refused_not_recursed(): void
    {
        $group = SavedGroup::factory()->create(['is_dynamic' => true]);
        $group->update(['rule' => ['type' => 'saved_group', 'id' => $group->id]]);

        try {
            app(AudienceResolver::class)->resolveIds(AudienceRule::make('saved_group', ['id' => $group->id]));
            $this->fail('A self-referencing saved group resolved instead of being refused.');
        } catch (CircularAudienceRuleException $e) {
            // Legible to an operator: names the group, in resolution order.
            $this->assertStringContainsString((string) $group->id, $e->getMessage());
            $this->assertStringContainsString('re-enters itself', $e->getMessage());
            $this->assertSame([$group->id], $e->path);
        }
    }

    /**
     * MUTATION: same guard, exercised across two distinct groups rather than
     * one self-reference — the path the docblock calls out as the "true
     * cycle" case, as opposed to merely a long chain.
     */
    #[Test]
    public function two_groups_that_include_each_other_are_refused_and_named(): void
    {
        $a = SavedGroup::factory()->create(['is_dynamic' => true, 'name' => 'Group A']);
        $b = SavedGroup::factory()->create(['is_dynamic' => true, 'name' => 'Group B']);

        $a->update(['rule' => ['type' => 'saved_group', 'id' => $b->id]]);
        $b->update(['rule' => ['type' => 'saved_group', 'id' => $a->id]]);

        try {
            app(AudienceResolver::class)->resolveIds(AudienceRule::make('saved_group', ['id' => $a->id]));
            $this->fail('A mutual cycle between two saved groups resolved instead of being refused.');
        } catch (CircularAudienceRuleException $e) {
            // Caught as the IMMEDIATE cycle (short path), not merely as an
            // eventual hop-limit overflow after 20 more A<->B round trips —
            // the hop-limit message would ALSO name both groups, so that
            // alone would not tell the two failure modes apart.
            $this->assertStringContainsString('re-enters itself', $e->getMessage());
            $this->assertStringContainsString((string) $a->id, $e->getMessage());
            $this->assertStringContainsString((string) $b->id, $e->getMessage());
            $this->assertLessThanOrEqual(2, count($e->path), 'Caught late — via the hop limit, not the cycle check.');
        }
    }

    /**
     * A chain of DISTINCT groups, each one legitimate on its own, still has to
     * stop somewhere. `MAX_GROUP_HOPS` is that bound, and it is a separate
     * exception from a true cycle because there is no single group to point
     * at as the mistake.
     *
     * MUTATION: remove the `$hops > self::MAX_GROUP_HOPS` check (or the
     * `$hops++`) and this chain resolves — slowly, and without ever saying the
     * chain was unusually deep.
     */
    #[Test]
    public function a_chain_of_distinct_groups_deeper_than_the_hop_limit_is_refused(): void
    {
        $groups = [];

        for ($i = 0; $i <= AudienceResolver::MAX_GROUP_HOPS + 2; $i++) {
            $groups[] = SavedGroup::factory()->create(['is_dynamic' => true]);
        }

        // Chain them: 0 -> 1 -> 2 -> ... -> last (a static list, so the chain
        // terminates and the only way to hit a limit is depth, not a cycle).
        for ($i = 0; $i < count($groups) - 1; $i++) {
            $groups[$i]->update(['rule' => ['type' => 'saved_group', 'id' => $groups[$i + 1]->id]]);
        }

        try {
            app(AudienceResolver::class)->resolveIds(AudienceRule::make('saved_group', ['id' => $groups[0]->id]));
            $this->fail('A saved-group chain deeper than MAX_GROUP_HOPS resolved instead of being refused.');
        } catch (CircularAudienceRuleException $e) {
            $this->assertStringContainsString((string) AudienceResolver::MAX_GROUP_HOPS, $e->getMessage());
        }
    }

    /**
     * The negative control: a legitimate, non-cyclic chain of saved groups
     * (well under the hop limit) still resolves normally, so the guard is not
     * refusing everything.
     */
    #[Test]
    public function a_short_legitimate_chain_of_saved_groups_still_resolves(): void
    {
        $contact = Contact::query()->create([
            'source' => ContactSource::Manual->value,
            'full_name' => 'Amina Bello', 'mobile_primary' => '+2348000000001',
            'is_active' => true,
        ]);

        $leaf = SavedGroup::factory()->create(['is_dynamic' => false]);
        $leaf->members()->attach($contact->id, ['organization_id' => $this->organization->id]);

        $middle = SavedGroup::factory()->create(['is_dynamic' => true]);
        $middle->update(['rule' => ['type' => 'saved_group', 'id' => $leaf->id]]);

        $top = SavedGroup::factory()->create(['is_dynamic' => true]);
        $top->update(['rule' => ['type' => 'saved_group', 'id' => $middle->id]]);

        $ids = app(AudienceResolver::class)->resolveIds(AudienceRule::make('saved_group', ['id' => $top->id]));

        $this->assertTrue($ids->contains($contact->id));
    }

    /**
     * FIXED (Gate 1 retrospective, defect 4): `AlertController::estimate()`
     * now catches `CircularAudienceRuleException` and turns it into a 422
     * carrying the exception's own message, rather than letting it fall
     * through to a bare 500. The live recipient counter on the EMNS console
     * is operated during an incident, and a coordinator who builds a cyclic
     * saved-group audience must see the rich, named explanation the
     * exception carries — not whatever Laravel's default exception handler
     * does with an uncaught RuntimeException.
     *
     * MUTATION: remove the `catch (CircularAudienceRuleException $e)` block
     * in `AlertController::estimate()` (or revert it to rethrow) and this
     * goes back to a 500 with none of the exception's explanation reaching
     * the response.
     */
    #[Test]
    public function the_estimate_endpoint_surfaces_the_cycle_exceptions_message(): void
    {
        config()->set('app.debug', false);

        $group = SavedGroup::factory()->create(['is_dynamic' => true]);
        $group->update(['rule' => ['type' => 'saved_group', 'id' => $group->id]]);

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Cyclic audience',
            'message' => 'Test body.',
            'severity' => AlertSeverity::Advisory->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'saved_group', 'id' => $group->id],
        ]);

        $user = User::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Composer',
            'email' => 'composer@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = Role::findOrCreate('estimate-composer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('bcms.alert.compose', 'web'));
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson(route('bcms.alerts.estimate', $alert));

        // 422, not a bare 500 — and the body actually carries the named,
        // legible explanation the exception was built to give an operator.
        $response->assertStatus(422);
        $message = $response->json('error');
        $this->assertIsString($message);
        $this->assertStringContainsString('re-enters itself', $message);
        $this->assertStringContainsString((string) $group->id, $message);
    }

    /**
     * FIXED (Gate 1 retrospective, defect 4's dispatch path): before this
     * fix, `AlertController::dispatchAlert()` caught only
     * `InvalidArgumentException` around `AlertService::release()`, so a
     * cyclic saved-group audience on the LIVE dispatch route — not merely
     * the estimate preview — fell through to an uncaught
     * `CircularAudienceRuleException` (a bare 500) instead of the same
     * refusal `estimate()` now gives. It now catches
     * `InvalidArgumentException|CircularAudienceRuleException`, turns it into
     * a `ValidationException` on the `dispatch` field, and records the
     * refused attempt — because an alert somebody tried to dispatch with a
     * cyclic audience is exactly the event an auditor asks to see.
     *
     * MUTATION: narrow `dispatchAlert()`'s catch back to
     * `InvalidArgumentException` only (or delete the `recordAudit(...)` call
     * inside it) and this fails — either the exception escapes uncaught, or
     * no `alert.dispatch_refused` row is written.
     */
    #[Test]
    public function a_cyclic_audience_refuses_the_live_dispatch_and_is_audited(): void
    {
        $group = SavedGroup::factory()->create(['is_dynamic' => true]);
        $group->update(['rule' => ['type' => 'saved_group', 'id' => $group->id]]);

        $alert = app(AlertService::class)->compose([
            'organization_id' => $this->organization->id,
            'title' => 'Cyclic audience — live dispatch',
            'message' => 'Test body.',
            // Life-safety never respects quiet hours, so the dispatch route's
            // quiet-hours guard cannot mask the assertion below with an
            // unrelated `ValidationException`.
            'severity' => AlertSeverity::LifeSafety->value,
            'channels' => ['sms'],
            'audience_rule' => ['type' => 'saved_group', 'id' => $group->id],
        ]);

        $user = User::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Dispatcher',
            'email' => 'dispatcher@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = Role::findOrCreate('dispatch-composer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('bcms.alert.dispatch', 'web'));
        $user->assignRole($role);

        $response = $this->actingAs($user)->post(route('bcms.alerts.dispatch', $alert));

        $response->assertSessionHasErrors('dispatch');
        $errors = session('errors');
        $message = $errors->first('dispatch');
        $this->assertStringContainsString('re-enters itself', $message);
        $this->assertStringContainsString((string) $group->id, $message);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => \App\Models\Bcms\Alert::class,
            'auditable_id' => $alert->getKey(),
            'event' => 'alert.dispatch_refused',
        ]);

        $refusal = AuditLog::query()
            ->where('auditable_type', \App\Models\Bcms\Alert::class)
            ->where('auditable_id', $alert->getKey())
            ->where('event', 'alert.dispatch_refused')
            ->firstOrFail();
        $this->assertStringContainsString('re-enters itself', $refusal->after['reason'] ?? '');

        // Nothing was dispatched: the alert never left draft/status behind.
        $this->assertNotSame('dispatching', $alert->refresh()->status);
    }
}
