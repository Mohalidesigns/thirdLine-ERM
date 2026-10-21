<?php

namespace Tests\Unit\Tprm;

use App\Models\Tprm\AuditLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The spellings a TPRM audit writer may store in `actor_type` (ADR 0028).
 *
 * `actor_type` is a hashed field in a sealed chain, so a spelling stored once
 * is stored for ever. The vendor's is `portal`, not the TRD's `portal_user`:
 * nothing has ever written the latter. If this fails, a constant was renamed
 * and every reader written to the constants now disagrees with every stored row.
 */
class AuditLogActorTypesTest extends TestCase
{
    #[Test]
    public function the_canonical_spellings_are_pinned(): void
    {
        $this->assertSame(['user', 'portal', 'system'], AuditLog::ACTOR_TYPES);
    }

    #[Test]
    public function each_constant_holds_its_literal(): void
    {
        $this->assertSame('user', AuditLog::ACTOR_USER);
        $this->assertSame('portal', AuditLog::ACTOR_PORTAL);
        $this->assertSame('system', AuditLog::ACTOR_SYSTEM);
    }

    #[Test]
    public function the_trds_portal_user_is_not_a_recognised_spelling(): void
    {
        $this->assertNotContains('portal_user', AuditLog::ACTOR_TYPES);
    }
}
