<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\User;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The workspace tells the assessor where Submit sends the assessment and how
 * many people will be told, BEFORE they press it. Both come from the services
 * that do the submitting, so the sentence beside the button cannot disagree
 * with what the button does.
 */
class WorkspaceSubmitPropsTest extends ReviewTestCase
{
    private RcsaAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        app(RcsaCycleService::class)->open($this->makeCycle(), $this->actor);

        $this->assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
    }

    private function enableBuApproval(): void
    {
        $this->organization->forceFill([
            'settings' => array_merge((array) $this->organization->settings, [
                'rcsa' => ['bu_approval_required' => true],
            ]),
        ])->save();
    }

    private function page(): AssertableInertia
    {
        $response = $this->actingAs($this->actor)->get(route('rcsa.assessments.show', $this->assessment))->assertOk();

        return AssertableInertia::fromTestResponse($response);
    }

    #[Test]
    public function with_the_bu_step_off_it_goes_to_orm_and_counts_the_reviewers(): void
    {
        // The reviewer from ReviewTestCase, plus a second one. The assessor
        // does not count themselves.
        $this->userWith(self::REVIEW_PERMISSIONS);

        $this->page()
            ->where('assessment.submit_target', 'orm')
            ->where('assessment.reviewer_count', 2);
    }

    #[Test]
    public function with_the_bu_step_on_it_goes_to_the_head_and_counts_one(): void
    {
        $this->enableBuApproval();

        $head = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.approve']);
        $this->retail->forceFill(['head_id' => $head->id])->save();

        $this->page()
            ->where('assessment.submit_target', 'bu_head')
            ->where('assessment.reviewer_count', 1);
    }

    #[Test]
    public function with_the_bu_step_on_and_no_head_set_nobody_would_be_told(): void
    {
        $this->enableBuApproval();

        $this->page()
            ->where('assessment.submit_target', 'bu_head')
            ->where('assessment.reviewer_count', 0);
    }

    #[Test]
    public function a_head_who_is_the_assessor_is_not_counted(): void
    {
        $this->enableBuApproval();
        $this->retail->forceFill(['head_id' => $this->actor->id])->save();

        $this->page()->where('assessment.reviewer_count', 0);
    }

    #[Test]
    public function the_count_is_the_number_the_submission_then_notifies(): void
    {
        $this->userWith(self::REVIEW_PERMISSIONS);

        $expected = null;
        $this->page()->where('assessment.reviewer_count', function ($count) use (&$expected) {
            $expected = $count;

            return true;
        });

        foreach ($this->assessment->lines()->get() as $line) {
            $line->forceFill([
                'inherent_likelihood' => 1,
                'inherent_impact' => 1,
                'control_effectiveness' => 'Fully Achieved',
            ])->save();
        }

        $result = app(RcsaSubmissionService::class)->submit($this->assessment->fresh(), $this->actor);

        $this->assertSame($expected, $result['notified']);
        $this->assertSame($expected, DB::table('notifications_log')->where('type', 'rcsa.assessment.submitted')->count());
    }

    /**
     * The recipients are filtered in SQL now; `can()` is what the send used to
     * ask, and it is still the definition. Every way of holding the permission
     * is in here, and every way of NOT holding it — so the SQL filter and the
     * Gate cannot drift apart unnoticed.
     */
    #[Test]
    public function the_sql_filter_selects_exactly_the_users_who_can_review(): void
    {
        Permission::findOrCreate('rcsa_assessment.review');

        $mk = fn (string $name, ?int $org = null, bool $active = true) => User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'-'.uniqid().'@example.test',
            'password' => 'password',
            'organization_id' => $org ?? $this->organization->id,
            'is_active' => $active,
        ]);

        // Through a role that carries the permission.
        $role = Role::findOrCreate('orm-reviewer-role');
        $role->givePermissionTo('rcsa_assessment.review');
        $viaRole = $mk('Via Role');
        $viaRole->assignRole($role);

        // Directly.
        $direct = $mk('Direct Grant');
        $direct->givePermissionTo('rcsa_assessment.review');

        // The Gate::before bypass: holds nothing, passes can().
        Role::findOrCreate('super-admin');
        $superAdmin = $mk('Super Admin');
        $superAdmin->assignRole('super-admin');

        // Must NOT be counted.
        $mk('Holds Nothing');
        $inactive = $mk('Inactive Reviewer', active: false);
        $inactive->givePermissionTo('rcsa_assessment.review');
        $otherRole = Role::findOrCreate('unrelated-role');
        $mk('Unrelated Role')->assignRole($otherRole);
        $foreign = TenantContext::bypass(fn () => $mk('Foreign Reviewer', $this->otherOrg->id));
        $foreign->givePermissionTo('rcsa_assessment.review');

        // The definition: what the Gate says, over the tenant's active users but the assessor.
        $expected = User::query()
            ->where('organization_id', $this->organization->id)
            ->where('is_active', true)
            ->whereKeyNot($this->actor->id)
            ->get()
            ->filter(fn (User $user) => $user->can('rcsa_assessment.review'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $actual = app(RcsaSubmissionService::class)->submissionRecipients($this->assessment, $this->actor);
        sort($actual);

        $this->assertSame($expected, $actual);

        // And the cases are the ones intended, not an accident of the fixture.
        foreach ([$this->reviewer, $viaRole, $direct, $superAdmin] as $user) {
            $this->assertContains($user->id, $actual);
        }
        $this->assertNotContains($inactive->id, $actual);
        $this->assertNotContains($foreign->id, $actual);
        $this->assertNotContains($this->actor->id, $actual);
        $this->assertCount(4, $actual);
    }

    #[Test]
    public function an_unregistered_review_permission_leaves_only_super_admins_and_does_not_throw(): void
    {
        Permission::query()->where('name', 'rcsa_assessment.review')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super-admin');
        $superAdmin = User::create([
            'name' => 'Only Admin',
            'email' => 'only-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super-admin');

        $this->assertSame(
            [$superAdmin->id],
            app(RcsaSubmissionService::class)->submissionRecipients($this->assessment, $this->actor),
        );
    }

    #[Test]
    public function an_assessment_with_its_own_reviewer_notifies_only_that_reviewer(): void
    {
        $this->assessment->forceFill(['reviewer_id' => $this->reviewer->id])->save();
        $this->userWith(self::REVIEW_PERMISSIONS);

        $this->assertSame(
            [$this->reviewer->id],
            app(RcsaSubmissionService::class)->submissionRecipients($this->assessment->fresh(), $this->actor),
        );
    }

    #[Test]
    public function an_assessment_already_filed_reports_no_recipients(): void
    {
        $this->assessment->forceFill(['status' => RcsaAssessment::SUBMITTED])->save();

        $this->page()->where('assessment.reviewer_count', 0);
    }

    #[Test]
    public function the_submission_notice_reads_in_the_singular_for_one_risk(): void
    {
        foreach ($this->assessment->lines()->get() as $line) {
            $line->forceFill([
                'inherent_likelihood' => 1,
                'inherent_impact' => 1,
                'control_effectiveness' => 'Fully Achieved',
            ])->save();
        }

        app(RcsaSubmissionService::class)->submit($this->assessment->fresh(), $this->actor);

        $body = (string) DB::table('notifications_log')->where('type', 'rcsa.assessment.submitted')->value('body');

        $this->assertStringContainsString('1 risk, 0 above appetite', $body);
    }
}
