<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Rcsa\RcsaSystem;
use PHPUnit\Framework\Attributes\Test;

/**
 * The §7.3 rule catalogue, fired against a deliberately broken file.
 *
 * The acceptance criterion for P2 is that "every rule in §7.3 fires correctly
 * on a deliberately broken fixture", so the centrepiece here is one workbook
 * carrying one violation per row, asserted rule by rule.
 */
class ImportValidationTest extends ImportTestCase
{
    /* ------------------------------------------------------------------ */
    /*  Nothing reaches the universe before publish */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function staging_a_file_writes_nothing_to_the_universe(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'A second, entirely different operational risk statement.']),
        ]);

        $this->assertSame(RcsaImportBatch::VALIDATED, $batch->status);
        $this->assertSame(2, $batch->total_rows);

        // The whole design of the pipeline in one assertion.
        $this->assertSame(0, RcsaRegisterRisk::count(), 'Parsing must not write to the universe.');
    }

    /* ------------------------------------------------------------------ */
    /*  The rule catalogue */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_rule_in_the_catalogue_fires_on_a_broken_file(): void
    {
        // One published risk to collide with, for the duplicate rule.
        $existing = $this->makeRisk([
            'risk_no' => 'RETAIL-R1',
            'status' => RcsaRegisterRisk::PUBLISHED,
            'process_id' => $this->onboarding->id,
            'potential_risk' => 'A risk that already exists in the published universe.',
        ]);

        $batch = $this->stage([
            // 2 — no business unit
            $this->row(['business_unit' => '']),
            // 3 — a business unit that does not exist and is nothing like one
            $this->row(['business_unit' => 'Zzzz Department']),
            // 4 — a process that does not exist
            $this->row(['process' => 'Nonexistent Process', 'sub_process' => '']),
            // 5 — a sub-process that is not under the named process
            $this->row(['sub_process' => 'Payments Clearing']),
            // 6 — a sub-process with no process beside it
            $this->row(['process' => '', 'sub_process' => 'KYC Verification']),
            // 7 — risk statement too short
            $this->row(['potential_risk' => 'System failure']),
            // 8 — no risk statement at all
            $this->row(['potential_risk' => '']),
            // 9 — a category outside the approved thirteen
            $this->row(['risk_category' => 'Made Up Category']),
            // 10 — Others without the free-text column
            $this->row(['risk_category' => 'Others', 'secondary_categories' => '']),
            // 11 — no risk driver (warning only)
            $this->row([
                'risk_driver' => '',
                'potential_risk' => 'A risk with no driver recorded against it at all.',
            ]),
            // 12 — no control (warning only)
            $this->row([
                'existing_control' => '',
                'potential_risk' => 'A risk with no existing control recorded against it.',
            ]),
            // 13 — a control owner who is not a user (warning only)
            $this->row([
                'control_owner' => 'ghost@nowhere.test',
                'potential_risk' => 'A risk whose named control owner does not work here.',
            ]),
            // 14 — a risk number already used in that unit
            $this->row([
                'risk_no' => 'RETAIL-R1',
                'potential_risk' => 'A risk carrying a number another risk already holds.',
            ]),
            // 15 — a duplicate of the published risk. The placement has to
            // match too: the hash is (unit, process, sub-process, statement),
            // and the existing row has no sub-process.
            $this->row(['sub_process' => '', 'potential_risk' => $existing->potential_risk]),
            // 16 — a system that is not in the register (warning only)
            $this->row([
                'system' => 'Nonexistent Core System',
                'potential_risk' => 'A risk naming a system the register has never heard of.',
            ]),
            // 17 — a free-text field over the 2,000 character cap
            $this->row(['potential_risk' => str_repeat('x', 2100)]),
        ]);

        $rows = $batch->rows()->get()->keyBy('row_number');

        $assert = function (int $rowNumber, string $status, string $field, string $rule) use ($rows) {
            $row = $rows[$rowNumber] ?? null;

            $this->assertNotNull($row, "Row {$rowNumber} was not staged.");
            $this->assertSame($status, $row->status, sprintf(
                'Row %d should be %s. Its problems were: %s',
                $rowNumber,
                $status,
                json_encode($row->errors)
            ));

            $matched = collect($row->errors ?? [])
                ->contains(fn (array $error) => $error['field'] === $field && $error['rule'] === $rule);

            $this->assertTrue($matched, sprintf(
                'Row %d should have failed %s.%s. It reported: %s',
                $rowNumber,
                $field,
                $rule,
                json_encode($row->errors)
            ));
        };

        $assert(2, RcsaImportRow::ERROR, 'business_unit', 'required');
        $assert(3, RcsaImportRow::ERROR, 'business_unit', 'exists');
        $assert(4, RcsaImportRow::ERROR, 'process', 'exists');
        $assert(5, RcsaImportRow::ERROR, 'sub_process', 'exists');
        $assert(6, RcsaImportRow::ERROR, 'sub_process', 'parent');
        $assert(7, RcsaImportRow::ERROR, 'potential_risk', 'min');
        $assert(8, RcsaImportRow::ERROR, 'potential_risk', 'required');
        $assert(9, RcsaImportRow::ERROR, 'risk_category', 'required');
        $assert(10, RcsaImportRow::ERROR, 'secondary_categories', 'required_if');
        $assert(11, RcsaImportRow::WARNING, 'risk_driver', 'recommended');
        $assert(12, RcsaImportRow::WARNING, 'existing_control', 'recommended');
        $assert(13, RcsaImportRow::WARNING, 'control_owner', 'normalisation');
        $assert(14, RcsaImportRow::ERROR, 'risk_no', 'unique');
        $assert(15, RcsaImportRow::DUPLICATE, 'potential_risk', 'duplicate');
        $assert(16, RcsaImportRow::WARNING, 'system', 'normalisation');
        $assert(17, RcsaImportRow::ERROR, 'potential_risk', 'max');

        // A warning row still writes; an error row does not. That distinction
        // is the whole reason there are three severities.
        $this->assertTrue($rows[11]->willWrite());
        $this->assertFalse($rows[7]->willWrite());
        $this->assertFalse($rows[15]->willWrite(), 'A duplicate defaults to skip.');
    }

    /* ------------------------------------------------------------------ */
    /*  Normalisation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function whitespace_and_casing_are_not_a_different_value(): void
    {
        $batch = $this->stage([
            $this->row([
                'business_unit' => "  retail   BANKING \u{00A0}",
                'risk_category' => 'compliance/regulatory',
                'control_type' => 'DETECTIVE',
            ]),
        ]);

        $row = $batch->rows()->sole();

        $this->assertSame(RcsaImportRow::VALID, $row->status, json_encode($row->errors));
        $this->assertSame($this->retail->id, $row->normalised['business_unit_id']);
        $this->assertSame('Compliance/Regulatory', $row->normalised['risk_category']);
        $this->assertSame('detective', $row->normalised['control_type']);
    }

    #[Test]
    public function the_alias_map_accepts_the_spellings_the_workbook_itself_teaches(): void
    {
        // Defect D1: the workbook's Risk Matrix sheet says "Moderate" while its
        // own dropdown says "Medium", so a file filled in from the printed
        // matrix uses a word the client's own template taught the user.
        $normaliser = app(\App\Services\Rcsa\RcsaImportNormaliser::class);

        $impact = ['Very Low', 'Low', 'Medium', 'High', 'Very High'];

        $this->assertSame('Medium', $normaliser->canonical('Moderate', $impact));
        $this->assertSame('Medium', $normaliser->canonical('medium', $impact));

        $categories = \App\Support\Rcsa\RcsaMethodologyTemplate::RISK_CATEGORIES;

        $this->assertSame('IT/Cybersecurity', $normaliser->canonical('IT Risk', $categories));
        $this->assertSame('Third-Party/Outsourcing', $normaliser->canonical('Vendor', $categories));

        // An alias that lands outside the vocabulary being asked about does not
        // count — "Medium" is an impact rating, not a risk category.
        $this->assertNull($normaliser->canonical('Moderate', $categories));
    }

    #[Test]
    public function a_near_miss_business_unit_is_matched_with_a_warning_not_silently(): void
    {
        $batch = $this->stage([
            $this->row(['business_unit' => 'Retail Bankng']),
        ]);

        $row = $batch->rows()->sole();

        // Matched, so the row is usable — but flagged, because a fuzzy match
        // that silently attached sixty risks to the wrong unit would be far
        // worse than sixty rejected rows.
        $this->assertSame(RcsaImportRow::WARNING, $row->status);
        $this->assertSame($this->retail->id, $row->normalised['business_unit_id']);
        $this->assertStringContainsString('matched to', implode(' ', $row->messages()));
    }

    #[Test]
    public function a_unit_code_names_a_unit_just_as_its_name_does(): void
    {
        $batch = $this->stage([$this->row(['business_unit' => 'RETAIL'])]);

        $row = $batch->rows()->sole();

        $this->assertSame(RcsaImportRow::VALID, $row->status, json_encode($row->errors));
        $this->assertSame($this->retail->id, $row->normalised['business_unit_id']);
    }

    #[Test]
    public function systems_are_a_semicolon_separated_list(): void
    {
        $finacle = RcsaSystem::create([
            'organization_id' => $this->organization->id,
            'code' => 'FIN', 'name' => 'Finacle', 'is_active' => true,
        ]);
        $swift = RcsaSystem::create([
            'organization_id' => $this->organization->id,
            'code' => 'SWIFT', 'name' => 'SWIFT Gateway', 'is_active' => true,
        ]);

        $batch = $this->stage([$this->row(['system' => 'Finacle; SWIFT Gateway'])]);

        $row = $batch->rows()->sole();

        $this->assertSame([$finacle->id, $swift->id], $row->normalised['system_ids']);
    }

    #[Test]
    public function dates_are_read_in_the_shapes_a_bank_actually_types(): void
    {
        // Unused by the universe import; exercised now so it is right when the
        // assessment round-trip of §10.4 arrives.
        $normaliser = app(\App\Services\Rcsa\RcsaImportNormaliser::class);

        $this->assertSame('2026-03-31', $normaliser->date('31/03/2026'));
        $this->assertSame('2026-03-31', $normaliser->date('2026-03-31'));
        $this->assertSame('2026-03-31', $normaliser->date('31-03-2026'));
        // An Excel serial, which is what a date cell becomes through a plain reader.
        $this->assertSame('2026-03-31', $normaliser->date(46112));
        $this->assertNull($normaliser->date('not a date'));
        $this->assertNull($normaliser->date(''));
    }

    /* ------------------------------------------------------------------ */
    /*  The file itself */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function blank_rows_are_skipped_rather_than_reported_as_errors(): void
    {
        // Excel hands back trailing empty rows for any sheet somebody has
        // scrolled through. Reporting them as sixteen missing-business-unit
        // errors would bury the real ones.
        $batch = $this->stage([
            $this->row(),
            array_map(fn () => '', $this->row()),
            array_map(fn () => '', $this->row()),
        ]);

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
    }

    #[Test]
    public function row_numbers_match_what_the_user_sees_in_excel(): void
    {
        $batch = $this->stage([
            $this->row(),
            $this->row(['potential_risk' => 'A second risk, on the third row of the sheet.']),
        ]);

        // Row 1 is the header, so the first data row is 2. An error naming
        // "row 1" would send the user to the header.
        $this->assertSame([2, 3], $batch->rows()->pluck('row_number')->all());
    }

    #[Test]
    public function a_file_missing_a_required_column_is_refused_with_the_column_named(): void
    {
        $header = array_map(fn (array $meta) => $meta['label'], array_values(\App\Services\Rcsa\RcsaTemplateWriter::COLUMNS));
        $header[1] = 'Something Else Entirely'; // was Business Unit

        $this->expectExceptionMessageMatches('/Business Unit/');

        $this->stage([$this->row()], $header);
    }

    #[Test]
    public function a_column_the_template_does_not_define_is_ignored_rather_than_refused(): void
    {
        // Users add a notes column. Refusing the file over it would be gratuitous.
        $header = array_map(fn (array $meta) => $meta['label'], array_values(\App\Services\Rcsa\RcsaTemplateWriter::COLUMNS));
        $header[] = 'Reviewer notes';

        $batch = $this->stage([$this->row()], $header);

        $this->assertSame(1, $batch->valid_rows);
    }
}
