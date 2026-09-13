<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaImportPublisher;
use PHPUnit\Framework\Attributes\Test;

/**
 * Publishing a staged batch into the universe.
 */
class ImportPublishTest extends ImportTestCase
{
    /* ------------------------------------------------------------------ */
    /*  The merge */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function rows_repeating_a_risk_become_one_risk_with_several_controls(): void
    {
        // The template asks for ONE ROW PER CONTROL, so this is the normal
        // shape of a real file. Writing these as three risks would triple the
        // register on the first upload — the single most consequential thing
        // about the file format.
        $batch = $this->stage([
            $this->row(['existing_control' => 'Dual review of account opening packs.']),
            $this->row(['existing_control' => 'Daily exception report reviewed by the branch manager.']),
            $this->row(['existing_control' => 'Quarterly KYC remediation sweep.']),
        ]);

        $this->assertSame(3, $batch->total_rows);

        $result = app(RcsaImportPublisher::class)->publish($batch, $this->actor);

        $this->assertSame(1, $result['created']);

        $risk = RcsaRegisterRisk::sole();

        $this->assertCount(3, $risk->controls);
        $this->assertSame('RETAIL-R1', $risk->risk_no);
        $this->assertSame(RcsaRegisterRisk::DRAFT, $risk->status, 'An imported risk is a draft until someone publishes it.');
        $this->assertSame($batch->id, $risk->source_batch_id);
    }

    #[Test]
    public function two_different_risks_stay_two_risks(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Payments are released without the second authoriser approving them.']),
        ]);

        app(RcsaImportPublisher::class)->publish($batch, $this->actor);

        $this->assertSame(2, RcsaRegisterRisk::count());
        $this->assertSame(['RETAIL-R1', 'RETAIL-R2'], RcsaRegisterRisk::orderBy('id')->pluck('risk_no')->all());
    }

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_batch_with_errors_cannot_be_published_without_saying_so(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Too short']),
        ]);

        $this->assertSame(1, $batch->error_rows);

        $this->expectExceptionMessageMatches('/valid rows only/');

        app(RcsaImportPublisher::class)->publish($batch, $this->actor);
    }

    #[Test]
    public function publishing_valid_rows_only_leaves_the_broken_ones_behind(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Too short']),
            $this->row(['potential_risk' => 'Payments are released without the second authoriser approving them.']),
        ]);

        $result = app(RcsaImportPublisher::class)->publish(
            $batch, $this->actor, RcsaImportPublisher::MODE_CREATE, validOnly: true
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, RcsaRegisterRisk::count());

        // The batch keeps the record of what was in the file, including the
        // row that did not make it — a bad row is never silently dropped.
        $this->assertSame(3, $batch->fresh()->total_rows);
        $this->assertSame(1, $batch->rows()->where('status', RcsaImportRow::ERROR)->count());
    }

    #[Test]
    public function a_batch_cannot_be_published_twice(): void
    {
        $batch = $this->stage([$this->row()]);

        app(RcsaImportPublisher::class)->publish($batch, $this->actor);

        $this->assertSame(1, RcsaRegisterRisk::count());

        $this->expectExceptionMessageMatches('/already been published/');

        app(RcsaImportPublisher::class)->publish($batch->fresh(), $this->actor);
    }

    /* ------------------------------------------------------------------ */
    /*  Duplicates */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_duplicate_is_skipped_by_default_and_updated_on_request(): void
    {
        $existing = $this->makeRisk([
            'risk_no' => 'RETAIL-LEGACY-1',
            'status' => RcsaRegisterRisk::PUBLISHED,
            'process_id' => $this->onboarding->id,
            'sub_process_id' => $this->kyc->id,
            'potential_risk' => 'Customer accounts are opened without complete KYC documentation.',
            'risk_driver' => 'The original wording of the driver.',
        ]);

        $batch = $this->stage([
            $this->row(['risk_driver' => 'A revised statement of the root cause.']),
        ]);

        $this->assertSame(1, $batch->duplicate_rows);

        /* --- Default: skip -------------------------------------------- */

        $result = app(RcsaImportPublisher::class)->publish($batch, $this->actor);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertSame('The original wording of the driver.', $existing->fresh()->risk_driver);

        /* --- Update mode ----------------------------------------------- */

        $second = $this->stage([
            $this->row(['risk_driver' => 'A revised statement of the root cause.']),
        ]);

        $result = app(RcsaImportPublisher::class)->publish(
            $second, $this->actor, RcsaImportPublisher::MODE_CREATE_UPDATE
        );

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, RcsaRegisterRisk::count(), 'Updating must not also create.');

        $existing->refresh();

        $this->assertSame('A revised statement of the root cause.', $existing->risk_driver);
        // The number is kept: it may already appear in a board paper.
        $this->assertSame('RETAIL-LEGACY-1', $existing->risk_no);
    }

    #[Test]
    public function updating_adds_controls_rather_than_replacing_them(): void
    {
        $existing = $this->makeRisk([
            'risk_no' => 'RETAIL-LEGACY-1',
            'status' => RcsaRegisterRisk::PUBLISHED,
            'process_id' => $this->onboarding->id,
            'sub_process_id' => $this->kyc->id,
            'potential_risk' => 'Customer accounts are opened without complete KYC documentation.',
        ]);

        $existing->controls()->create([
            'organization_id' => $this->organization->id,
            'description' => 'A control the file does not mention.',
        ]);

        $batch = $this->stage([$this->row(['existing_control' => 'A control the file adds.'])]);

        app(RcsaImportPublisher::class)->publish($batch, $this->actor, RcsaImportPublisher::MODE_CREATE_UPDATE);

        $descriptions = $existing->fresh()->controls->pluck('description')->all();

        // An update from a partial file must not delete controls the file
        // simply did not mention.
        $this->assertContains('A control the file does not mention.', $descriptions);
        $this->assertContains('A control the file adds.', $descriptions);
    }

    #[Test]
    public function re_uploading_the_same_file_does_not_double_the_controls(): void
    {
        $batch = $this->stage([$this->row()]);
        app(RcsaImportPublisher::class)->publish($batch, $this->actor);

        $risk = RcsaRegisterRisk::sole();
        $risk->update(['status' => RcsaRegisterRisk::PUBLISHED]);

        $again = $this->stage([$this->row()]);
        app(RcsaImportPublisher::class)->publish($again, $this->actor, RcsaImportPublisher::MODE_CREATE_UPDATE);

        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertCount(1, $risk->fresh()->controls, 'The same control described twice is one control.');
    }

    #[Test]
    public function update_only_mode_writes_nothing_new(): void
    {
        $batch = $this->stage([$this->row()]);

        $result = app(RcsaImportPublisher::class)->publish(
            $batch, $this->actor, RcsaImportPublisher::MODE_UPDATE
        );

        // The user asked to refresh what is already there; quietly adding rows
        // they did not ask for is not that.
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, RcsaRegisterRisk::count());
    }

    /* ------------------------------------------------------------------ */
    /*  Atomicity */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_publish_that_fails_part_way_writes_nothing(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'Payments are released without the second authoriser approving them.']),
            $this->row(['potential_risk' => 'Reconciliation breaks are carried forward for months without escalation.']),
        ]);

        // Make the third row's write fail: the business unit it resolved to is
        // deleted between staging and publishing, so the foreign key rejects it.
        $third = $batch->rows()->orderByDesc('row_number')->first();
        $normalised = $third->normalised;
        $normalised['business_unit_id'] = 999_999;
        $third->update(['normalised' => $normalised]);

        try {
            app(RcsaImportPublisher::class)->publish($batch, $this->actor);
            $this->fail('The publish should have failed on the unresolvable business unit.');
        } catch (\Throwable) {
            // Expected.
        }

        // All or nothing. A half-published batch would leave the user unable to
        // tell which rows landed, so they would upload again and duplicate them.
        $this->assertSame(0, RcsaRegisterRisk::count());
        $this->assertSame(RcsaImportBatch::VALIDATED, $batch->fresh()->status);
    }
}
