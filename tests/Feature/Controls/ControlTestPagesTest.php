<?php

namespace Tests\Feature\Controls;

use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** Phase 3.4: the control testing pages and the test lifecycle. */
class ControlTestPagesTest extends ControlsTestCase
{
    #[Test]
    public function the_dashboard_renders_its_figures(): void
    {
        $this->makeTest(['status' => 'completed', 'result' => 'effective', 'test_code' => 'CT-0002']);

        $this->actingAs($this->actor)
            ->get(route('risk.control-tests.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ControlTests/Dashboard')
                ->where('totalTests', 1)
                ->where('completedTests', 1)
                ->where('passRate', 100)
                ->has('recentTests', 1)
                ->has('upcomingTests', 0)
            );
    }

    #[Test]
    public function the_create_page_can_be_opened_against_a_chosen_control(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.control-tests.create', ['control_id' => $this->control->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ControlTests/Create')
                ->where('controlId', $this->control->id)
                ->has('controls', 1)
                ->has('options.types', 4)
            );
    }

    #[Test]
    public function the_detail_page_reports_what_the_caller_may_do(): void
    {
        $test = $this->makeTest();

        $this->actingAs($this->actor)
            ->get(route('risk.control-tests.show', $test))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ControlTests/Show')
                ->where('test.test_code', 'CT-0001')
                ->where('control.control_code', $this->control->control_code)
                ->has('evidence', 0)
                // Scheduled: startable, not yet completable.
                ->where('can.start', true)
                ->where('can.complete', false)
                ->where('can.review', false)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The lifecycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_scheduled_test_starts_and_only_once(): void
    {
        $test = $this->makeTest();

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.start', $test))
            ->assertRedirect();

        $this->assertSame('in_progress', $test->fresh()->status);

        // The lifecycle guard answers with a message, not a 403.
        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.start', $test))
            ->assertSessionHas('error', 'Only a scheduled test can be started.');
    }

    #[Test]
    public function a_test_without_a_reviewer_completes_on_submission(): void
    {
        $test = $this->makeTest(['status' => 'in_progress', 'reviewer_id' => null]);

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.complete', $test), ['result' => 'effective', 'score' => 90])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // There is nothing to approve, so no review is waited for.
        $this->assertSame('completed', $test->fresh()->status);
    }

    #[Test]
    public function a_test_with_a_reviewer_waits_for_review(): void
    {
        $reviewer = $this->userWith(['control_test.view', 'control_test.review'], 'reviewer@example.test');
        $test = $this->makeTest(['status' => 'in_progress', 'reviewer_id' => $reviewer->id]);

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.complete', $test), ['result' => 'partially_effective'])
            ->assertRedirect();

        $this->assertSame('pending_review', $test->fresh()->status);

        // And the reviewer — not the tester — is the one who can decide.
        $this->actingAs($reviewer)
            ->get(route('risk.control-tests.show', $test))
            ->assertInertia(fn (Assert $page) => $page->where('can.review', true));
    }

    #[Test]
    public function a_rejected_test_returns_to_the_tester_for_rework(): void
    {
        $test = $this->makeTest(['status' => 'rejected', 'tester_id' => $this->actor->id, 'result' => 'ineffective']);

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.resubmit', $test))
            ->assertRedirect();

        $test->refresh();

        $this->assertSame('in_progress', $test->status);
        // `not_tested`, the enum's own "no result yet" value — the column is
        // NOT NULL, so clearing it to null was a 500.
        $this->assertSame('not_tested', $test->result, 'the rejected result is cleared so it is recorded afresh');
    }

    #[Test]
    public function only_a_test_pending_review_can_be_reviewed(): void
    {
        $reviewer = $this->userWith(['control_test.review'], 'reviewer2@example.test');
        $test = $this->makeTest(['status' => 'in_progress', 'reviewer_id' => $reviewer->id]);

        $this->actingAs($reviewer)
            ->post(route('risk.control-tests.review', $test), ['action' => 'approve'])
            ->assertSessionHas('error', 'Only tests pending review can be reviewed.');
    }

    #[Test]
    public function a_rejection_requires_a_reason(): void
    {
        $reviewer = $this->userWith(['control_test.review'], 'reviewer3@example.test');
        $test = $this->makeTest(['status' => 'pending_review', 'reviewer_id' => $reviewer->id]);

        $this->actingAs($reviewer)
            ->post(route('risk.control-tests.review', $test), ['action' => 'reject'])
            ->assertSessionHasErrors('rejection_reason');
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function evidence_is_stored_on_the_private_disk(): void
    {
        // WP-11: evidence used to go to the `public` disk, whose root is
        // symlinked into the document root, so a guessed URL returned audit
        // evidence to anyone. It must not be reachable except through the
        // download action.
        Storage::fake('local');

        $test = $this->makeTest(['status' => 'in_progress']);

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.upload-evidence', $test), [
                'file' => UploadedFile::fake()->create('walkthrough.pdf', 64, 'application/pdf'),
                'description' => 'Signed walkthrough notes',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $evidence = ControlTestEvidence::where('control_test_id', $test->id)->firstOrFail();

        $this->assertStringStartsWith('control-test-evidence/'.$test->id, $evidence->file_path);
        $this->assertStringNotContainsString('public', $evidence->file_path);
        // Derived from the bytes, not from the tail of the client's filename.
        $this->assertNotSame('', (string) $evidence->file_type);
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    #[Test]
    public function an_executable_file_is_refused(): void
    {
        Storage::fake('local');

        $test = $this->makeTest(['status' => 'in_progress']);

        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.upload-evidence', $test), [
                'file' => UploadedFile::fake()->create('payload.php', 8, 'application/x-httpd-php'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ControlTestEvidence::where('control_test_id', $test->id)->count());
    }

    #[Test]
    public function evidence_belonging_to_another_test_is_refused(): void
    {
        Storage::fake('local');

        $mine = $this->makeTest(['status' => 'in_progress']);
        $other = $this->makeTest(['status' => 'in_progress', 'test_code' => 'CT-0003']);

        $this->actingAs($this->actor)->post(route('risk.control-tests.upload-evidence', $other), [
            'file' => UploadedFile::fake()->create('theirs.pdf', 16, 'application/pdf'),
        ]);

        $evidence = ControlTestEvidence::where('control_test_id', $other->id)->firstOrFail();

        $this->actingAs($this->actor)
            ->get(route('risk.control-tests.download-evidence', [$mine->id, $evidence->id]))
            ->assertForbidden();
    }

    #[Test]
    public function a_viewer_cannot_upload_evidence(): void
    {
        $viewer = $this->userWith(['control_test.view'], 'viewer-ct@example.test');
        $test = $this->makeTest(['status' => 'in_progress']);

        $this->actingAs($viewer)
            ->post(route('risk.control-tests.upload-evidence', $test), [
                'file' => UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function no_form_request_uses_the_string_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Controls/*.php')) as $file) {
            $this->assertStringNotContainsString(
                "'exists:",
                (string) file_get_contents($file),
                basename($file).' uses a string exists rule, which is not tenant-scoped',
            );
        }
    }

    #[Test]
    public function a_control_from_another_organisation_cannot_be_tested(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.store'), [
                'control_id' => $this->foreignControl->id,
                'title' => 'Theirs',
                'test_type' => 'walkthrough',
                'tester_id' => $this->actor->id,
                'scheduled_date' => '2026-06-30',
            ])
            ->assertSessionHasErrors('control_id');

        $this->assertSame(0, ControlTest::where('title', 'Theirs')->count());
    }

    #[Test]
    public function a_tester_from_another_organisation_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.control-tests.store'), [
                'control_id' => $this->control->id,
                'title' => 'Quarterly walkthrough',
                'test_type' => 'walkthrough',
                'tester_id' => $this->otherActor->id,
                'scheduled_date' => '2026-06-30',
            ])
            ->assertSessionHasErrors('tester_id');
    }
}
