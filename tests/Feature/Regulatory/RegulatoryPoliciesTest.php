<?php

namespace Tests\Feature\Regulatory;

use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\RegulatoryFiling;
use App\Models\RiskTaxonomy;
use App\Models\User;
use App\Policies\RegulatoryCircularPolicy;
use App\Policies\RegulatoryDeadlinePolicy;
use App\Policies\RiskTaxonomyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The three regulatory policies (migration Phase 5.3).
 *
 * DEVIATION FROM THE PHASE PROMPT, and the reason for it. The prompt asks for a
 * single `RegulatoryPolicy`. Laravel discovers a policy from the SUBJECT's
 * class — `App\Models\RegulatoryCircular` resolves only to
 * `App\Policies\RegulatoryCircularPolicy` — so one policy named for the MODULE
 * would never be reached by any of the three models it covers, and every
 * ability on it would silently return false. That is 3.8's trap and 4.1's, and
 * this module has three models, so it gets three policies.
 */
class RegulatoryPoliciesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['regulatory.view', 'regulatory.manage', 'regulatory.file'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(RegulatoryCircularPolicy::class, Gate::getPolicyFor(RegulatoryCircular::class));
        $this->assertInstanceOf(RegulatoryDeadlinePolicy::class, Gate::getPolicyFor(RegulatoryDeadline::class));
        $this->assertInstanceOf(RiskTaxonomyPolicy::class, Gate::getPolicyFor(RiskTaxonomy::class));
    }

    /**
     * FILING IS NOT SCHEDULING. `regulatory.file` and `regulatory.manage` are
     * separate seeded permissions and the routes have always distinguished
     * them; nothing enforced the split beyond the middleware until this policy.
     */
    #[Test]
    public function filing_a_return_is_separate_from_managing_the_calendar(): void
    {
        $scheduler = $this->userWith(['regulatory.view', 'regulatory.manage'], 'scheduler');
        $filer = $this->userWith(['regulatory.view', 'regulatory.file'], 'filer');

        $deadline = $this->deadline();

        $this->assertTrue($scheduler->can('create', RegulatoryDeadline::class));
        $this->assertFalse($scheduler->can('file', $deadline));

        $this->assertTrue($filer->can('file', $deadline));
        $this->assertFalse($filer->can('create', RegulatoryDeadline::class));
    }

    /** A reader may look and nothing more. */
    #[Test]
    public function a_reader_may_look_and_nothing_more(): void
    {
        $reader = $this->userWith(['regulatory.view'], 'reader');

        $circular = $this->circular();

        $this->assertTrue($reader->can('view', $circular));
        $this->assertFalse($reader->can('create', RegulatoryCircular::class));
        $this->assertFalse($reader->can('assessCompliance', $circular));
        $this->assertFalse($reader->can('create', RiskTaxonomy::class));
    }

    /**
     * Assessing compliance asks for `regulatory.manage` — the permission the
     * update-compliance route has always carried. Tightening it to
     * `regulatory.file` would read plausibly and would silently lock the panel
     * for every existing role holding manage without file.
     */
    #[Test]
    public function assessing_compliance_asks_for_the_permission_the_route_always_required(): void
    {
        $manager = $this->userWith(['regulatory.view', 'regulatory.manage'], 'manager');

        $this->assertTrue($manager->can('assessCompliance', $this->circular()));
    }

    /** Filing through the screen records against the deadline and closes it. */
    #[Test]
    public function filing_records_against_the_deadline_and_marks_it_submitted(): void
    {
        $filer = $this->userWith(['regulatory.view', 'regulatory.file'], 'filer2');
        $deadline = $this->deadline();

        $this->actingAs($filer)
            ->post(route('risk.regulatory.submit-filing', $deadline), [
                'filing_date' => now()->toDateString(),
                'document_ref' => 'ORMS-2026-Q2',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('submitted', $deadline->fresh()->status);

        $filing = RegulatoryFiling::firstOrFail();
        $this->assertSame($deadline->id, $filing->deadline_id);
        $this->assertSame($filer->id, $filing->filed_by);
        $this->assertSame('submitted', $filing->status);
    }

    /** Somebody without the filing permission cannot record one. */
    #[Test]
    public function a_scheduler_cannot_record_a_filing(): void
    {
        $scheduler = $this->userWith(['regulatory.view', 'regulatory.manage'], 'scheduler2');
        $deadline = $this->deadline();

        $this->actingAs($scheduler)
            ->post(route('risk.regulatory.submit-filing', $deadline), ['filing_date' => now()->toDateString()])
            ->assertForbidden();

        $this->assertSame(0, RegulatoryFiling::count());
        $this->assertSame('upcoming', $deadline->fresh()->status);
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $handle): User
    {
        $user = User::create([
            'name' => $handle,
            'email' => $handle.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function circular(): RegulatoryCircular
    {
        return RegulatoryCircular::create([
            'organization_id' => $this->organization->id,
            'regulator' => 'CBN',
            'circular_ref' => 'CIR-2026-001',
            'title' => 'Operational risk capital guidance',
            'date_issued' => now()->subMonth(),
        ]);
    }

    private function deadline(): RegulatoryDeadline
    {
        return RegulatoryDeadline::create([
            'organization_id' => $this->organization->id,
            'regulator' => 'CBN',
            'report_type' => 'ORMS return',
            'title' => 'Quarterly ORMS',
            'deadline_date' => now()->addMonth(),
            'frequency' => 'quarterly',
            'status' => 'upcoming',
        ]);
    }
}
