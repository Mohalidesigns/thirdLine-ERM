<?php

namespace Tests\Feature\Rcsa;

use App\Policies\RcsaPolicy;
use App\Support\Rcsa\RcsaProgramme;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * RcsaPolicy (migration Phase 3.8) — the one policy in the product registered
 * by hand, because RCSA has no model to discover one from.
 */
class RcsaPolicyTest extends RcsaTestCase
{
    #[Test]
    public function the_policy_is_registered_for_the_programme_subject(): void
    {
        $this->assertInstanceOf(RcsaPolicy::class, Gate::getPolicyFor(RcsaProgramme::class));
    }

    #[Test]
    public function reading_needs_rcsa_view(): void
    {
        $reader = $this->userWith(['rcsa.view'], 'reader@example.test');
        $nobody = $this->userWith([], 'nobody@example.test');

        $this->assertTrue($reader->can('viewAny', RcsaProgramme::class));
        $this->assertFalse($nobody->can('viewAny', RcsaProgramme::class));
    }

    #[Test]
    public function filing_needs_rcsa_submit(): void
    {
        $reader = $this->userWith(['rcsa.view'], 'reader2@example.test');
        $filer = $this->userWith(['rcsa.view', 'rcsa.submit'], 'filer@example.test');

        $this->assertFalse($reader->can('submit', RcsaProgramme::class));
        $this->assertTrue($filer->can('submit', RcsaProgramme::class));
    }

    /**
     * rcsa.view does not imply rcsa.submit: a reviewer reads the assessment
     * somebody else filed.
     */
    #[Test]
    public function reading_does_not_imply_filing(): void
    {
        $reader = $this->userWith(['rcsa.view'], 'reader3@example.test');

        $this->assertTrue($reader->can('viewAny', RcsaProgramme::class));
        $this->assertFalse($reader->can('submit', RcsaProgramme::class));
    }

    #[Test]
    public function super_admin_passes_through_gate_before(): void
    {
        $admin = $this->userWith([], 'admin@example.test', ['super-admin']);

        $this->assertTrue($admin->can('viewAny', RcsaProgramme::class));
        $this->assertTrue($admin->can('submit', RcsaProgramme::class));
    }
}
