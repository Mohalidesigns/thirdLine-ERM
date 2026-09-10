<?php

namespace Tests\Feature\Console;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Finding;
use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Findings\FindingService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms:sweep-actions` — the nightly CAPA sweep (ISO 22301 clause 10.1).
 *
 * `CorrectiveActionService::sweep()` and `ErmBridge::pullClosedIssues()` both
 * have their own logic; this file covers the wrapper's own job — the
 * per-organisation loop and the feature flag gate — and pins the idempotency
 * `sweep()` earns by construction: an action already `overdue` no longer
 * matches the `whereIn(['open','in_progress'])` the sweep re-runs against, so
 * a second run marks nothing a second time.
 */
class SweepBcmsCorrectiveActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $author;

    private User $doer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->author = $this->user('author@khb.test');
        $this->doer = $this->user('doer@khb.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_marks_an_overdue_action_reopens_a_lapsed_acceptance_and_pulls_a_closed_issue(): void
    {
        $overdueAction = $this->actionFor($this->raiseFinding('BCA overdue candidate'), [
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $lapsedFinding = $this->raiseFinding('BCA lapsed acceptance');
        $lapsedAction = $this->actionFor($lapsedFinding);
        app(CorrectiveActionService::class)->acceptRisk(
            $lapsedAction, 'Deemed low likelihood for this cycle.', $this->author->id,
            now()->subDay()->toDateString(),
        );

        $mirroredFinding = $this->raiseFinding('BCA mirrored to an issue that gets closed in ERM');
        $issueId = $mirroredFinding->refresh()->erm_issue_id;
        $this->assertNotNull($issueId, 'The finding was not mirrored, so the pull-back half of this test proves nothing.');
        Issue::query()->whereKey($issueId)->withoutGlobalScopes()->update(['issue_status' => 'CLOSED']);

        $this->artisan('bcms:sweep-actions')->assertSuccessful();

        $this->assertSame(CorrectiveActionStatus::Overdue, $overdueAction->refresh()->status);
        $this->assertSame(CorrectiveActionStatus::Open, $lapsedAction->refresh()->status);
        $this->assertNull($lapsedAction->refresh()->acceptance_expires_on);
        $this->assertSame('closed', $mirroredFinding->refresh()->status, 'A finding whose mirrored ERM issue closed was not pulled back closed.');
    }

    #[Test]
    public function running_it_twice_marks_nothing_a_second_time(): void
    {
        $action = $this->actionFor($this->raiseFinding('BCA idempotency check'), [
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('bcms:sweep-actions')
            ->expectsOutputToContain('1 action(s) marked overdue')
            ->assertSuccessful();

        $this->assertSame(CorrectiveActionStatus::Overdue, $action->refresh()->status);

        $this->artisan('bcms:sweep-actions')
            ->expectsOutputToContain('0 action(s) marked overdue')
            ->assertSuccessful();
    }

    #[Test]
    public function it_no_ops_quietly_when_there_is_nothing_to_sweep(): void
    {
        $this->artisan('bcms:sweep-actions')
            ->expectsOutputToContain('0 action(s) marked overdue, 0 acceptance(s) reopened, 0 finding(s) closed')
            ->assertSuccessful();
    }

    #[Test]
    public function it_no_ops_when_the_feature_is_switched_off(): void
    {
        config()->set('features.bcms', false);

        $this->actionFor($this->raiseFinding('Should not be touched'), [
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('bcms:sweep-actions')
            ->expectsOutputToContain('nothing to sweep')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $stillOpen = \App\Models\Bcms\CorrectiveAction::query()->where('status', CorrectiveActionStatus::Open->value)->count();
        TenantContext::clear();

        $this->assertSame(1, $stillOpen, 'The switched-off command touched a row anyway.');
    }

    #[Test]
    public function each_organisation_is_swept_under_its_own_tenant_context(): void
    {
        $actionA = $this->actionFor($this->raiseFinding('Bank A overdue'), [
            'due_date' => now()->subDay()->toDateString(),
        ]);
        TenantContext::clear();

        $orgB = Organization::create([
            'name' => 'Second Bank', 'short_name' => 'SB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($orgB->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($orgB->id);

        $authorB = User::create([
            'name' => 'Author B', 'email' => 'author@sb.test', 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $orgB->id, 'is_active' => true,
        ]);
        $findingB = app(FindingService::class)->raise(
            FindingSource::GapAnalysis, FindingClassification::Improvement, 'Bank B finding.', null,
            ['iso_clause_ref' => IsoClauseRef::Iso22301_8_3->value, 'severity' => 'medium'], $authorB->id,
        );
        $actionB = app(CorrectiveActionService::class)->create(
            $findingB, 'Fix it', ['due_date' => now()->subDay()->toDateString()], $authorB->id,
        );
        TenantContext::clear();

        $this->artisan('bcms:sweep-actions')
            ->expectsOutputToContain('2 action(s) marked overdue')
            ->assertSuccessful();

        $this->assertSame(CorrectiveActionStatus::Overdue, $actionA->refresh()->status);
        $this->assertSame(CorrectiveActionStatus::Overdue, $actionB->refresh()->status);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    private function raiseFinding(string $description): Finding
    {
        return app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Improvement,
            $description,
            null,
            ['iso_clause_ref' => IsoClauseRef::Iso22301_8_3->value, 'severity' => 'medium'],
            $this->author->id,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function actionFor(Finding $finding, array $attributes = []): \App\Models\Bcms\CorrectiveAction
    {
        return app(CorrectiveActionService::class)->create(
            $finding, 'Fix it', array_merge(['owner_id' => $this->doer->id], $attributes), $this->author->id,
        );
    }
}
