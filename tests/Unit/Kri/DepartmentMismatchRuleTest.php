<?php

namespace Tests\Unit\Kri;

use App\Models\BusinessUnit;
use App\Services\Kri\DepartmentMismatchRule;
use App\Support\DirectoryProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0026 section 9 / decision 0.5: the directory's department text against
 * the unit's name and code. Business units are authoritative; the output is a
 * display flag and never moves a contact. UNKNOWN IS NOT A MISMATCH.
 */
class DepartmentMismatchRuleTest extends TestCase
{
    private function unit(string $name = 'Internal Audit', string $code = 'BU-IA'): BusinessUnit
    {
        return new BusinessUnit(['name' => $name, 'code' => $code]);
    }

    private function profile(?string $department, bool $synced = true): DirectoryProfile
    {
        return new DirectoryProfile(synced: $synced, department: $department, accountEnabled: true);
    }

    #[Test]
    public function no_profile_is_not_a_mismatch(): void
    {
        $this->assertFalse(DepartmentMismatchRule::mismatches(null, $this->unit()));
    }

    #[Test]
    public function a_user_the_directory_does_not_know_is_not_a_mismatch(): void
    {
        $this->assertFalse(DepartmentMismatchRule::mismatches(DirectoryProfile::notSynced(), $this->unit()));
        // Even if department text were somehow present, an unsynced profile says nothing.
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('Retail Banking', synced: false), $this->unit()));
    }

    #[Test]
    public function a_synced_user_with_no_department_text_is_not_a_mismatch(): void
    {
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile(null), $this->unit()));
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile(''), $this->unit()));
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('   '), $this->unit()));
    }

    #[Test]
    public function the_unit_name_matches_ignoring_case_and_spacing(): void
    {
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('internal audit'), $this->unit()));
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('  INTERNAL   AUDIT '), $this->unit()));
    }

    #[Test]
    public function the_unit_code_matches_too(): void
    {
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('BU-IA'), $this->unit()));
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('bu ia'), $this->unit()));
    }

    #[Test]
    public function punctuation_and_ampersands_do_not_cause_a_false_flag(): void
    {
        $unit = $this->unit('Risk and Compliance', 'BU-RC');

        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('Risk & Compliance'), $unit));
        $this->assertFalse(DepartmentMismatchRule::mismatches($this->profile('Risk, Compliance'), $this->unit('Risk, Compliance', 'X')));
    }

    #[Test]
    public function a_person_who_moved_department_is_flagged(): void
    {
        $this->assertTrue(DepartmentMismatchRule::mismatches($this->profile('Retail Banking'), $this->unit()));
    }

    #[Test]
    public function a_partial_name_is_a_mismatch_not_a_match(): void
    {
        $this->assertTrue(DepartmentMismatchRule::mismatches($this->profile('Audit'), $this->unit()));
        $this->assertTrue(DepartmentMismatchRule::mismatches($this->profile('Internal Audit and Inspection'), $this->unit()));
    }
}
