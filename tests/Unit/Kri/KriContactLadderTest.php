<?php

namespace Tests\Unit\Kri;

use App\Enums\KriContactRole;
use App\Support\Kri\KriContactLadder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0026 section 7: ONE function, two consumers (reminders and Issue
 * escalation). 1 = champions, 2 = + team leads, 3 = + unit heads, 4 and above =
 * + group heads.
 */
class KriContactLadderTest extends TestCase
{
    /** @return array<string, array{0: int, 1: list<string>}> */
    public static function levels(): array
    {
        return [
            'level 1 reaches champions only' => [1, ['risk_champion']],
            'level 2 adds team leads' => [2, ['risk_champion', 'team_lead']],
            'level 3 adds unit heads' => [3, ['risk_champion', 'team_lead', 'unit_head']],
            'level 4 adds group heads' => [4, ['risk_champion', 'team_lead', 'unit_head', 'group_head']],
            'level 5 is still the whole ladder' => [5, ['risk_champion', 'team_lead', 'unit_head', 'group_head']],
            'level 99 is still the whole ladder' => [99, ['risk_champion', 'team_lead', 'unit_head', 'group_head']],
            'level 0 is clamped up to champions' => [0, ['risk_champion']],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('levels')]
    public function the_roles_reached_are_cumulative(int $level, array $expected): void
    {
        $this->assertSame(
            $expected,
            array_map(fn (KriContactRole $r) => $r->value, KriContactLadder::rolesForLevel($level))
        );
    }

    #[Test]
    public function a_reminders_tier_is_its_sequence_capped_at_four(): void
    {
        $this->assertSame(1, KriContactLadder::tierForSequence(1));
        $this->assertSame(2, KriContactLadder::tierForSequence(2));
        $this->assertSame(3, KriContactLadder::tierForSequence(3));
        $this->assertSame(4, KriContactLadder::tierForSequence(4));
        $this->assertSame(4, KriContactLadder::tierForSequence(9));
        $this->assertSame(1, KriContactLadder::tierForSequence(0));
    }

    #[Test]
    public function the_issue_floor_ladder_is_1_7_14_30_days(): void
    {
        $this->assertSame([1 => 1, 2 => 7, 3 => 14, 4 => 30], KriContactLadder::ISSUE_FLOOR_DAYS);

        $this->assertSame(0, KriContactLadder::issueLevelForDaysOverdue(0));
        $this->assertSame(1, KriContactLadder::issueLevelForDaysOverdue(1));
        $this->assertSame(1, KriContactLadder::issueLevelForDaysOverdue(6));
        $this->assertSame(2, KriContactLadder::issueLevelForDaysOverdue(7));
        $this->assertSame(2, KriContactLadder::issueLevelForDaysOverdue(13));
        $this->assertSame(3, KriContactLadder::issueLevelForDaysOverdue(14));
        $this->assertSame(3, KriContactLadder::issueLevelForDaysOverdue(29));
        $this->assertSame(4, KriContactLadder::issueLevelForDaysOverdue(30));
        $this->assertSame(4, KriContactLadder::issueLevelForDaysOverdue(400));
    }

    #[Test]
    public function the_set_of_roles_is_frozen_at_four(): void
    {
        $this->assertSame(['risk_champion', 'team_lead', 'unit_head', 'group_head'], KriContactRole::values());
    }
}
