<?php

namespace Tests\Feature;

use App\Models\RiskAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every audit action name the application writes must fit the column it is
 * written into.
 *
 * This test exists because the suite could not otherwise catch the class of bug
 * it guards: SQLite stores a column's declared VARCHAR length and then ignores
 * it, so an over-long insert passes in every test and throws SQLSTATE[22001] on
 * MySQL. `kri_breach_escalation` sat undetected behind a nightly command that
 * had never successfully run; `threshold_rebaselined` was added by WP-04 and
 * was one release away from doing the same.
 *
 * So the assertion is made against the DECLARED width, parsed out of the
 * schema, rather than by attempting an insert and hoping the driver objects.
 */
class AuditActionTypeWidthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every action name passed to AuditTrailService::record() anywhere in the
     * application, plus the ones the migrations write.
     *
     * Kept as an explicit list rather than grepped at runtime: a test that
     * discovers its own inputs from source is a test that quietly stops
     * checking anything the day the regex drifts.
     *
     * @var list<string>
     */
    private const ACTION_TYPES = [
        // Generic CRUD, written by most controllers.
        'create', 'created', 'update', 'updated', 'delete', 'deleted', 'commented',
        // Approvals.
        'approval_granted', 'approval_rejected',
        // KRI escalation (App\Listeners\EscalateRiskOnKriBreach).
        'kri_breach_escalation',
        // WP-04 measure engine.
        'period_closed', 'period_reopened',
        'threshold_rebaselined',
        'breach_acknowledged', 'breach_closed',
        'fx_rate_recorded', 'fx_rate_amended',
    ];

    #[Test]
    public function every_audit_action_type_fits_the_column(): void
    {
        $limit = RiskAuditTrail::ACTION_TYPE_MAX;

        $tooLong = array_values(array_filter(
            self::ACTION_TYPES,
            fn (string $action) => mb_strlen($action) > $limit
        ));

        $this->assertSame(
            [],
            $tooLong,
            "These audit action types are longer than risk_audit_trail.action_type ({$limit} chars) "
            ."and will throw SQLSTATE[22001] on MySQL while passing silently on SQLite:\n  "
            .implode("\n  ", array_map(fn ($a) => $a.' ('.mb_strlen($a).')', $tooLong))
        );
    }

    #[Test]
    public function the_entity_type_column_fits_every_morph_alias(): void
    {
        $limit = RiskAuditTrail::ENTITY_TYPE_MAX;

        $tooLong = array_values(array_filter(
            array_keys(\App\Support\MorphTypes::map()),
            fn (string $alias) => mb_strlen($alias) > $limit
        ));

        $this->assertSame([], $tooLong, 'Morph aliases longer than risk_audit_trail.entity_type: '.implode(', ', $tooLong));
    }

    /**
     * Where the driver DOES report a length, it must agree with the constant.
     *
     * MySQL reports `varchar(64)`; SQLite declares VARCHAR without a length and
     * reports none, in which case there is nothing to reconcile and the
     * constants above are the whole contract.
     */
    #[Test]
    public function the_schema_agrees_with_the_declared_constants(): void
    {
        foreach ([
            'action_type' => RiskAuditTrail::ACTION_TYPE_MAX,
            'entity_type' => RiskAuditTrail::ENTITY_TYPE_MAX,
        ] as $column => $expected) {
            $actual = $this->declaredLength('risk_audit_trail', $column);

            if ($actual === null) {
                continue;
            }

            $this->assertSame(
                $expected,
                $actual,
                "risk_audit_trail.{$column} is varchar({$actual}) but the application promises {$expected}."
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The declared length of a string column, or null when the driver does not
     * report one (SQLite).
     */
    private function declaredLength(string $table, string $column): ?int
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] !== $column) {
                continue;
            }

            if (preg_match('/\((\d+)\)/', (string) $definition['type'], $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
